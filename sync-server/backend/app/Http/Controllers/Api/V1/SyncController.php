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
        $user = $request->user();

        return response()->json([
            'provider_accounts' => $user->providerAccounts()->with('provider')->get(),
            'attribution_rules' => $user->attributionRules()->with(['provider', 'providerAccount', 'device'])->get(),
            'subscriptions' => $user->subscriptions()->with(['providerAccount', 'provider'])->get(),
            'projects' => $user->projects()->with('projectRoots')->get(),
            'model_prices' => ModelPrice::whereNull('effective_to')->get(),
            'sync_cursor' => now()->toIso8601String(),
        ]);
    }
}
