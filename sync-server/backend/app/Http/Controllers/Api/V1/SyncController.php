<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Models\ModelPrice;
use App\Models\Subscription;
use App\Services\Sync\IngestUsageEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SyncController extends Controller
{
    public function __construct(
        private IngestUsageEvents $ingest,
    ) {}

    public function events(Request $request): JsonResponse
    {
        if ($this->isDemoUser($request)) {
            return response()->json(['error' => 'Demo account does not accept sync uploads.'], 403);
        }

        $data = $request->validate([
            'device_uuid' => ['required', 'string'],
            'client_batch_id' => ['required', 'string'],
            'events' => ['required', 'array'],
            'events.*.source_event_id' => ['required', 'string'],
            'events.*.source_event_hash' => ['required', 'string'],
            'events.*.source_file_hash' => ['nullable', 'string'],
            'events.*.source_offset' => ['nullable', 'integer'],
            'events.*.provider_id' => ['required', 'string', 'exists:providers,id'],
            'events.*.timestamp' => ['required', 'date'],
            'events.*.model' => ['nullable', 'string'],
            'events.*.project' => ['nullable', 'array'],
            'events.*.project.root_path' => ['nullable', 'string'],
            'events.*.project.display_name' => ['nullable', 'string'],
            'events.*.conversation' => ['nullable', 'array'],
            'events.*.conversation.external_conversation_id' => ['nullable', 'string'],
            'events.*.conversation.display_name' => ['nullable', 'string'],
            'events.*.tokens.input' => ['required', 'integer', 'min:0'],
            'events.*.tokens.output' => ['required', 'integer', 'min:0'],
            'events.*.tokens.cached_input' => ['required', 'integer', 'min:0'],
            'events.*.tokens.cache_write' => ['required', 'integer', 'min:0'],
            'events.*.tokens.cache_read' => ['required', 'integer', 'min:0'],
            'events.*.tokens.reasoning' => ['required', 'integer', 'min:0'],
            'events.*.tokens.tool' => ['required', 'integer', 'min:0'],
            'events.*.tokens.unknown' => ['required', 'integer', 'min:0'],
            'events.*.client_cost' => ['nullable', 'array'],
            'events.*.client_cost.official_api_cost_usd' => ['nullable', 'numeric'],
            'events.*.client_cost.pricing_match_confidence' => ['nullable', 'string'],
            'events.*.warnings' => ['nullable', 'array'],
            'events.*.client_created_at' => ['nullable', 'date'],
            'events.*.client_updated_at' => ['nullable', 'date'],
        ]);

        $device = Device::where([
            'user_id' => $request->user()->id,
            'device_uuid' => $data['device_uuid'],
        ])->firstOrFail();

        $result = $this->ingest->handle($device, $data['client_batch_id'], $data['events']);

        return response()->json($result);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        if ($this->isDemoUser($request)) {
            return response()->json(['error' => 'Demo account does not accept sync uploads.'], 403);
        }

        $data = $request->validate([
            'subscriptions' => ['required', 'array'],
            'subscriptions.*.source_subscription_id' => ['required', 'string', 'max:255'],
            'subscriptions.*.provider_id' => ['required', 'string', 'exists:providers,id'],
            'subscriptions.*.product_name' => ['required', 'string', 'max:255'],
            'subscriptions.*.monthly_amount' => ['required', 'numeric', 'min:0'],
            'subscriptions.*.currency' => ['required', 'string', 'max:8'],
            'subscriptions.*.billing_anchor_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'subscriptions.*.enabled' => ['boolean'],
            'subscriptions.*.notes' => ['nullable', 'string'],
        ]);

        $synced = 0;

        foreach ($data['subscriptions'] as $subscription) {
            $match = [
                'user_id' => $request->user()->id,
                'source_subscription_id' => $subscription['source_subscription_id'],
            ];

            $payload = [
                'provider_account_id' => null,
                'provider_id' => $subscription['provider_id'],
                'plan_name' => $subscription['product_name'],
                'monthly_price' => $subscription['monthly_amount'],
                'currency' => strtoupper($subscription['currency']),
                'billing_anchor_day' => $subscription['billing_anchor_day'] ?? null,
                'active' => $subscription['enabled'] ?? true,
                'notes' => $subscription['notes'] ?? null,
            ];

            $existing = Subscription::where($match)->first()
                ?? Subscription::where([
                    'user_id' => $request->user()->id,
                    'provider_id' => $subscription['provider_id'],
                    'plan_name' => $subscription['product_name'],
                ])->first();

            if ($existing) {
                $existing->update(array_merge($payload, [
                    'source_subscription_id' => $subscription['source_subscription_id'],
                ]));
            } else {
                $request->user()->subscriptions()->create(array_merge($payload, [
                    'source_subscription_id' => $subscription['source_subscription_id'],
                ]));
            }

            $synced++;
        }

        return response()->json([
            'ok' => true,
            'synced' => $synced,
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        if ($this->isDemoUser($request)) {
            return response()->json(['error' => 'Demo account settings cannot be downloaded.'], 403);
        }

        $user = $request->user();

        // Only send model prices for models this user actually has events for.
        // For new users with no events, fall back to all active prices.
        $usedModels = \App\Models\UsageEvent::where('usage_events.user_id', $user->id)
            ->whereNotNull('model')
            ->select('provider_id', 'model')
            ->distinct()
            ->get();

        $allPrices = ModelPrice::with('provider')
            ->whereNull('effective_to')
            ->orderBy('provider_id')
            ->orderBy('model')
            ->get();

        $modelPrices = $usedModels->isEmpty()
            ? $allPrices
            : $allPrices->filter(function ($price) use ($usedModels) {
                // Direct model name match
                $hasDirectMatch = $usedModels->contains(function ($used) use ($price) {
                    return $used->provider_id === $price->provider_id
                        && strtolower($used->model) === strtolower($price->model);
                });
                if ($hasDirectMatch) {
                    return true;
                }

                // Alias match
                $aliases = json_decode($price->aliases_json ?? '[]', true);
                foreach ($aliases as $alias) {
                    $hasAliasMatch = $usedModels->contains(function ($used) use ($price, $alias) {
                        return $used->provider_id === $price->provider_id
                            && strtolower($used->model) === strtolower($alias);
                    });
                    if ($hasAliasMatch) {
                        return true;
                    }
                }

                return false;
            })
            ->values();

        return response()->json([
            'provider_accounts' => $user->providerAccounts()->with('provider')->get(),
            'attribution_rules' => $user->attributionRules()->with(['provider', 'providerAccount', 'device'])->get(),
            'subscriptions' => $user->subscriptions()->with(['providerAccount', 'provider'])->get(),
            'projects' => $user->projects()->with('projectRoots')->get(),
            'model_prices' => $modelPrices,
            'sync_cursor' => now()->toIso8601String(),
        ]);
    }

    public function pricing(Request $request): JsonResponse
    {
        if ($this->isDemoUser($request)) {
            return response()->json(['error' => 'Demo account does not accept sync uploads.'], 403);
        }

        $data = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*.provider_id' => ['required', 'string', 'exists:providers,id'],
            'prices.*.model' => ['required', 'string'],
            'prices.*.aliases_json' => ['nullable', 'array'],
            'prices.*.input_per_1m' => ['nullable', 'numeric'],
            'prices.*.output_per_1m' => ['nullable', 'numeric'],
            'prices.*.cached_input_per_1m' => ['nullable', 'numeric'],
            'prices.*.cache_write_per_1m' => ['nullable', 'numeric'],
            'prices.*.cache_read_per_1m' => ['nullable', 'numeric'],
            'prices.*.reasoning_per_1m' => ['nullable', 'numeric'],
            'prices.*.tool_per_1m' => ['nullable', 'numeric'],
            'prices.*.source_url' => ['nullable', 'string'],
            'prices.*.catalog_version' => ['nullable', 'string'],
        ]);

        $synced = 0;
        foreach ($data['prices'] as $price) {
            $effectiveFrom = $price['effective_from'] ?? now()->toDateTimeString();

            $match = [
                'provider_id' => $price['provider_id'],
                'model' => $price['model'],
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
            ];

            $payload = [
                'aliases_json' => !empty($price['aliases_json']) ? json_encode($price['aliases_json']) : null,
                'currency' => $price['currency'] ?? 'USD',
                'input_per_1m' => $price['input_per_1m'] ?? null,
                'output_per_1m' => $price['output_per_1m'] ?? null,
                'cached_input_per_1m' => $price['cached_input_per_1m'] ?? null,
                'cache_write_per_1m' => $price['cache_write_per_1m'] ?? null,
                'cache_read_per_1m' => $price['cache_read_per_1m'] ?? null,
                'reasoning_per_1m' => $price['reasoning_per_1m'] ?? null,
                'tool_per_1m' => $price['tool_per_1m'] ?? null,
                'source_url' => $price['source_url'] ?? null,
                'catalog_version' => $price['catalog_version'] ?? 'client-sync',
                'user_override' => false,
            ];

            ModelPrice::updateOrCreate($match, $payload);
            $synced++;
        }

        return response()->json([
            'ok' => true,
            'synced' => $synced,
        ]);
    }

    private function isDemoUser(Request $request): bool
    {
        $user = $request->user();

        return $user && ($user->email === 'demo@metr.app' || $user->username === 'demo');
    }
}
