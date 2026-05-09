<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Models\ModelPrice;
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
