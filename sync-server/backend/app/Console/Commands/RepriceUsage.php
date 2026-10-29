<?php

namespace App\Console\Commands;

use App\Models\ModelPrice;
use App\Models\UsageEvent;
use App\Services\Pricing\RepriceModelUsage;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applies the current price catalog to stored usage events. Price updates
 * only add catalog rows; this brings existing events in line with them.
 */
class RepriceUsage extends Command
{
    protected $signature = 'metr:prices:reprice
        {--provider= : Only this provider}
        {--model= : Only this model (exact name as stored on events)}
        {--only-missing : Only events without a cost}
        {--changed-since= : Models whose price rows were created at or after this time, plus all events without a cost}
        {--dry-run : Show what would change, write nothing}';

    protected $description = 'Reprice stored usage events with the current model prices';

    public function handle(RepriceModelUsage $reprice): int
    {
        $pairs = $this->pairs();
        if ($pairs->isEmpty()) {
            $this->info('Nothing to reprice.');

            return self::SUCCESS;
        }

        $before = $this->totals();
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            DB::beginTransaction();
        }

        $rows = [];
        try {
            foreach ($pairs as [$providerId, $model, $onlyMissing]) {
                $written = $reprice->handle($providerId, $model, $onlyMissing);
                $rows[] = [$providerId, $model, $written];
            }
            $after = $this->totals();
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        $this->table(['provider', 'model', 'events changed'], array_filter($rows, fn ($row) => $row[2] > 0));
        $this->line(sprintf(
            '%sTotal cost %.2f -> %.2f USD; events without a cost %d -> %d.',
            $dryRun ? '[dry run] ' : '',
            $before['cost'],
            $after['cost'],
            $before['missing'],
            $after['missing'],
        ));
        $this->reportStillMissing();

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{0: string, 1: string, 2: bool}>
     */
    private function pairs()
    {
        $query = UsageEvent::query()
            ->whereNotNull('model')
            ->where('model', '!=', '')
            ->when($this->option('provider'), fn ($q, $p) => $q->where('provider_id', $p))
            ->when($this->option('model'), fn ($q, $m) => $q->where('model', $m));

        $changedSince = $this->option('changed-since');
        if ($changedSince === null) {
            $onlyMissing = (bool) $this->option('only-missing');

            return (clone $query)
                ->when($onlyMissing, fn ($q) => $q->whereNull('official_api_cost_usd'))
                ->distinct()
                ->get(['provider_id', 'model'])
                ->map(fn ($row) => [$row->provider_id, $row->model, $onlyMissing]);
        }

        // Names (model + aliases) of price rows created since the given time.
        $changed = [];
        ModelPrice::query()
            ->where('created_at', '>=', Carbon::parse($changedSince))
            ->get(['provider_id', 'model', 'aliases_json'])
            ->each(function (ModelPrice $price) use (&$changed) {
                $changed[$price->provider_id][strtolower($price->model)] = true;
                foreach (json_decode($price->aliases_json ?? '[]', true) ?: [] as $alias) {
                    if (is_string($alias) && $alias !== '') {
                        $changed[$price->provider_id][strtolower($alias)] = true;
                    }
                }
            });

        $all = (clone $query)->distinct()->get(['provider_id', 'model']);
        $missing = (clone $query)->whereNull('official_api_cost_usd')->distinct()->get(['provider_id', 'model'])
            ->mapWithKeys(fn ($row) => [$row->provider_id."\0".$row->model => true]);

        return $all
            ->filter(fn ($row) => isset($changed[$row->provider_id][strtolower($row->model)])
                || isset($missing[$row->provider_id."\0".$row->model]))
            ->map(fn ($row) => [
                $row->provider_id,
                $row->model,
                // Models without a new price only need their unpriced events looked at.
                ! isset($changed[$row->provider_id][strtolower($row->model)]),
            ])
            ->values();
    }

    /**
     * @return array{cost: float, missing: int}
     */
    private function totals(): array
    {
        $row = UsageEvent::query()
            ->when($this->option('provider'), fn ($q, $p) => $q->where('provider_id', $p))
            ->selectRaw('COALESCE(SUM(official_api_cost_usd), 0) as cost, SUM(CASE WHEN official_api_cost_usd IS NULL THEN 1 ELSE 0 END) as missing')
            ->first();

        return ['cost' => (float) $row->cost, 'missing' => (int) $row->missing];
    }

    /** Why events still have no cost: no price for the model, or only a total token count. */
    private function reportStillMissing(): void
    {
        $rows = UsageEvent::query()
            ->whereNull('official_api_cost_usd')
            ->when($this->option('provider'), fn ($q, $p) => $q->where('provider_id', $p))
            ->groupBy('provider_id', 'model', 'pricing_match_confidence')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(15)
            ->get(['provider_id', 'model', 'pricing_match_confidence', DB::raw('COUNT(*) as events')])
            ->map(fn ($row) => [
                $row->provider_id,
                $row->model ?: '(none)',
                match ($row->pricing_match_confidence) {
                    'missing' => 'no price, or only total tokens known',
                    'missing_price' => 'price lacks a needed rate',
                    default => (string) $row->pricing_match_confidence,
                },
                $row->events,
            ]);
        if ($rows->isNotEmpty()) {
            $this->line('Still without a cost (top 15):');
            $this->table(['provider', 'model', 'reason', 'events'], $rows->all());
        }
    }
}
