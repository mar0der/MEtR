<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UsageEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $query = UsageEvent::where('user_id', $request->user()->id);

        if ($from) {
            $query->where('timestamp', '>=', $from);
        }
        if ($to) {
            $query->where('timestamp', '<=', $to);
        }

        $aggregates = (clone $query)->select([
            DB::raw('SUM(input_tokens) as input_tokens'),
            DB::raw('SUM(output_tokens) as output_tokens'),
            DB::raw('SUM(cached_input_tokens) as cached_input_tokens'),
            DB::raw('SUM(cache_write_tokens) as cache_write_tokens'),
            DB::raw('SUM(cache_read_tokens) as cache_read_tokens'),
            DB::raw('SUM(reasoning_tokens) as reasoning_tokens'),
            DB::raw('SUM(tool_tokens) as tool_tokens'),
            DB::raw('SUM(unknown_tokens) as unknown_tokens'),
            DB::raw('COUNT(*) as event_count'),
            DB::raw('SUM(CASE WHEN official_api_cost_usd IS NULL THEN 1 ELSE 0 END) as missing_price_count'),
            DB::raw('SUM(CASE WHEN provider_account_id IS NULL THEN 1 ELSE 0 END) as unknown_account_count'),
            DB::raw('SUM(official_api_cost_usd) as total_cost'),
        ])->first();

        return response()->json([
            'input_tokens' => (int) $aggregates->input_tokens,
            'output_tokens' => (int) $aggregates->output_tokens,
            'cached_input_tokens' => (int) $aggregates->cached_input_tokens,
            'cache_write_tokens' => (int) $aggregates->cache_write_tokens,
            'cache_read_tokens' => (int) $aggregates->cache_read_tokens,
            'reasoning_tokens' => (int) $aggregates->reasoning_tokens,
            'tool_tokens' => (int) $aggregates->tool_tokens,
            'unknown_tokens' => (int) $aggregates->unknown_tokens,
            'event_count' => (int) $aggregates->event_count,
            'missing_price_count' => (int) $aggregates->missing_price_count,
            'unknown_account_count' => (int) $aggregates->unknown_account_count,
            'total_cost' => $aggregates->total_cost ? (float) $aggregates->total_cost : null,
        ]);
    }

    public function byDevice(Request $request): JsonResponse
    {
        return $this->aggregateBy($request, 'device_id');
    }

    public function byProject(Request $request): JsonResponse
    {
        return $this->aggregateBy($request, 'project_id');
    }

    public function byProviderAccount(Request $request): JsonResponse
    {
        return $this->aggregateBy($request, 'provider_account_id');
    }

    public function byModel(Request $request): JsonResponse
    {
        return $this->aggregateBy($request, 'model');
    }

    private function aggregateBy(Request $request, string $groupColumn): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $query = UsageEvent::where('user_id', $request->user()->id);

        if ($from) {
            $query->where('timestamp', '>=', $from);
        }
        if ($to) {
            $query->where('timestamp', '<=', $to);
        }

        $results = $query->select([
            $groupColumn,
            DB::raw('SUM(input_tokens) as input_tokens'),
            DB::raw('SUM(output_tokens) as output_tokens'),
            DB::raw('SUM(cached_input_tokens) as cached_input_tokens'),
            DB::raw('SUM(cache_write_tokens) as cache_write_tokens'),
            DB::raw('SUM(cache_read_tokens) as cache_read_tokens'),
            DB::raw('SUM(reasoning_tokens) as reasoning_tokens'),
            DB::raw('SUM(tool_tokens) as tool_tokens'),
            DB::raw('SUM(unknown_tokens) as unknown_tokens'),
            DB::raw('COUNT(*) as event_count'),
            DB::raw('SUM(CASE WHEN official_api_cost_usd IS NULL THEN 1 ELSE 0 END) as missing_price_count'),
            DB::raw('SUM(CASE WHEN provider_account_id IS NULL THEN 1 ELSE 0 END) as unknown_account_count'),
            DB::raw('SUM(official_api_cost_usd) as total_cost'),
        ])
            ->groupBy($groupColumn)
            ->get();

        return response()->json($results);
    }
}
