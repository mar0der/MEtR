<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UsageEvent;
use App\Services\Accounts\AttributeProviderAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AccountAttributionRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rules = $request->user()->attributionRules()->with(['provider', 'providerAccount', 'device'])->get();

        return response()->json($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_id' => ['nullable', 'string', 'exists:providers,id'],
            'provider_account_id' => ['required', 'string', 'exists:provider_accounts,id'],
            'device_id' => ['nullable', 'string', 'exists:devices,id'],
            'project_id' => ['nullable', 'string', 'exists:projects,id'],
            'source_path_pattern' => ['nullable', 'string'],
            'model_pattern' => ['nullable', 'string'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'priority' => ['integer'],
            'enabled' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $rule = $request->user()->attributionRules()->create($data);

        return response()->json($rule, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $rule = $request->user()->attributionRules()->findOrFail($id);

        $data = $request->validate([
            'provider_id' => ['nullable', 'string', 'exists:providers,id'],
            'provider_account_id' => ['string', 'exists:provider_accounts,id'],
            'device_id' => ['nullable', 'string', 'exists:devices,id'],
            'project_id' => ['nullable', 'string', 'exists:projects,id'],
            'source_path_pattern' => ['nullable', 'string'],
            'model_pattern' => ['nullable', 'string'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'priority' => ['integer'],
            'enabled' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $rule->update($data);

        return response()->json($rule);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $rule = $request->user()->attributionRules()->findOrFail($id);
        $rule->delete();

        return response()->json(['ok' => true]);
    }

    public function reapply(Request $request): JsonResponse
    {
        $user = $request->user();
        $attributor = app(AttributeProviderAccount::class);

        $events = UsageEvent::where('user_id', $user->id)
            ->where('account_attribution_confidence', '!=', 'manual')
            ->get();

        $updated = 0;
        foreach ($events as $event) {
            $attribution = $attributor->handle(
                userId: $user->id,
                deviceId: $event->device_id,
                providerId: $event->provider_id,
                model: $event->model,
                projectId: $event->project_id,
                timestamp: $event->timestamp,
            );

            if ($event->provider_account_id !== $attribution['provider_account_id']) {
                $event->update([
                    'provider_account_id' => $attribution['provider_account_id'],
                    'account_attribution_confidence' => $attribution['confidence'],
                    'account_attribution_reason' => $attribution['reason'],
                ]);
                $updated++;
            }
        }

        return response()->json(['updated' => $updated]);
    }
}
