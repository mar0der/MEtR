<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UsageEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subscriptions = $request->user()->subscriptions()->with(['providerAccount', 'provider'])->get();

        return response()->json($subscriptions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_account_id' => ['nullable', 'string', 'exists:provider_accounts,id'],
            'provider_id' => ['required', 'string', 'exists:providers,id'],
            'plan_name' => ['required', 'string'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string'],
            'billing_anchor_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'started_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date'],
            'active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $subscription = $request->user()->subscriptions()->create($data);

        return response()->json($subscription, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $subscription = $request->user()->subscriptions()->findOrFail($id);

        $data = $request->validate([
            'provider_account_id' => ['nullable', 'string', 'exists:provider_accounts,id'],
            'provider_id' => ['string', 'exists:providers,id'],
            'plan_name' => ['string'],
            'monthly_price' => ['numeric', 'min:0'],
            'currency' => ['string'],
            'billing_anchor_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'started_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date'],
            'active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $subscription->update($data);

        return response()->json($subscription);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $subscription = $request->user()->subscriptions()->findOrFail($id);

        $hasEvents = UsageEvent::where('provider_account_id', $subscription->provider_account_id)->exists();

        if ($hasEvents) {
            $subscription->update(['active' => false]);

            return response()->json(['ok' => true, 'deactivated' => true]);
        }

        $subscription->delete();

        return response()->json(['ok' => true]);
    }
}
