<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UsageEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Read-only totals per device and provider, plus duplicate checks, so a
 * client (`metr reconcile`) can compare what it scanned with what the
 * server holds.
 */
class ReconcileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $totals = UsageEvent::query()
            ->where('usage_events.user_id', $userId)
            ->join('devices', 'devices.id', '=', 'usage_events.device_id')
            ->groupBy('usage_events.device_id', 'devices.device_uuid', 'devices.display_name', 'devices.last_seen_at', 'usage_events.provider_id')
            ->orderBy('devices.display_name')
            ->orderBy('usage_events.provider_id')
            ->get([
                'usage_events.device_id',
                'devices.device_uuid',
                'devices.display_name',
                'devices.last_seen_at',
                'usage_events.provider_id',
                DB::raw('COUNT(*) as events'),
                DB::raw('SUM(usage_events.input_tokens) as input_tokens'),
                DB::raw('SUM(usage_events.output_tokens) as output_tokens'),
                DB::raw('SUM(usage_events.cached_input_tokens) as cached_input_tokens'),
                DB::raw('SUM(usage_events.cache_write_tokens) as cache_write_tokens'),
                DB::raw('SUM(usage_events.cache_read_tokens) as cache_read_tokens'),
                DB::raw('SUM(usage_events.reasoning_tokens) as reasoning_tokens'),
                DB::raw('MIN(usage_events.timestamp) as first_event'),
                DB::raw('MAX(usage_events.timestamp) as last_event'),
            ])
            ->map(fn ($row) => [
                'device_id' => $row->device_id,
                'device_uuid' => $row->device_uuid,
                'device_name' => $row->display_name,
                'last_seen_at' => $row->last_seen_at,
                'provider_id' => $row->provider_id,
                'events' => (int) $row->events,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cached_input_tokens' => (int) $row->cached_input_tokens,
                'cache_write_tokens' => (int) $row->cache_write_tokens,
                'cache_read_tokens' => (int) $row->cache_read_tokens,
                'reasoning_tokens' => (int) $row->reasoning_tokens,
                'first_event' => $row->first_event,
                'last_event' => $row->last_event,
            ]);

        // The same raw log line stored twice for one device: should be zero.
        $copies = UsageEvent::query()
            ->where('user_id', $userId)
            ->groupBy('device_id', 'provider_id', 'source_event_hash')
            ->havingRaw('COUNT(*) > 1')
            ->select('device_id', 'provider_id', DB::raw('COUNT(*) as copies'));
        $sameDevice = DB::query()
            ->fromSub($copies, 'd')
            ->groupBy('device_id', 'provider_id')
            ->get(['device_id', 'provider_id', DB::raw('COUNT(*) as lines'), DB::raw('SUM(copies) - COUNT(*) as extra_rows')])
            ->map(fn ($row) => [
                'device_id' => $row->device_id,
                'provider_id' => $row->provider_id,
                'lines' => (int) $row->lines,
                'extra_rows' => (int) $row->extra_rows,
            ]);

        // The same raw line reported by two devices: only expected when a log
        // folder was copied between machines.
        $shared = UsageEvent::query()
            ->where('user_id', $userId)
            ->groupBy('provider_id', 'source_event_hash')
            ->havingRaw('COUNT(DISTINCT device_id) > 1')
            ->select('provider_id', DB::raw('COUNT(*) as rows_count'));
        $crossDevice = DB::query()
            ->fromSub($shared, 's')
            ->groupBy('provider_id')
            ->get(['provider_id', DB::raw('COUNT(*) as lines'), DB::raw('SUM(rows_count) as rows_count')])
            ->map(fn ($row) => [
                'provider_id' => $row->provider_id,
                'lines' => (int) $row->lines,
                'rows' => (int) $row->rows_count,
            ]);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'totals' => $totals,
            'duplicates' => [
                'same_device' => $sameDevice,
                'cross_device' => $crossDevice,
            ],
        ]);
    }
}
