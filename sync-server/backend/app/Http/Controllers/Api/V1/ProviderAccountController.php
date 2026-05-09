<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UsageEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProviderAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()->providerAccounts()->with('provider')->get();

        return response()->json($accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_id' => ['required', 'string', 'exists:providers,id'],
            'label' => ['required', 'string'],
            'account_type' => ['required', 'string', 'in:personal,team,enterprise,unknown'],
            'default_device_id' => ['nullable', 'string', 'exists:devices,id'],
            'external_account_hint_hash' => ['nullable', 'string'],
            'active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $account = $request->user()->providerAccounts()->create($data);

        return response()->json($account, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $account = $request->user()->providerAccounts()->findOrFail($id);

        $data = $request->validate([
            'provider_id' => ['string', 'exists:providers,id'],
            'label' => ['string'],
            'account_type' => ['string', 'in:personal,team,enterprise,unknown'],
            'default_device_id' => ['nullable', 'string', 'exists:devices,id'],
            'external_account_hint_hash' => ['nullable', 'string'],
            'active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $account->update($data);

        return response()->json($account);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $account = $request->user()->providerAccounts()->findOrFail($id);

        $hasEvents = UsageEvent::where('provider_account_id', $id)->exists();

        if ($hasEvents) {
            $account->update(['active' => false]);

            return response()->json(['ok' => true, 'deactivated' => true]);
        }

        $account->delete();

        return response()->json(['ok' => true]);
    }
}
