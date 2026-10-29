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
        {--changed-since= : Models whose price rows were added or changed at or after this time, plus all events without a cost}
        {--dry-run : Show what would change, write nothing}';

    protected $description = 'Reprice stored usage events with the current model prices';

    public function handle(RepriceModelUsage $reprice): int
    {
        $pairs = $this->pairs();
        if ($pairs->isEmpty()) {
            $this->info('Nothing to reprice.');

            return self::SUCCESS;
        }

        if ($this->option('only-missing') && $this->option('changed-since') !== null) {
            $this->error('Use either --only-missing or --changed-since (which already includes events without a cost).');

            return self::FAILURE;
        }

        $before = $this->totals();
        // Dry run computes the changes without writing, so no rows are locked.
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $delta = 0.0;
        foreach ($pairs as [$providerId, $model, $onlyMissing]) {
            $written = $reprice->handle($providerId, $model, $onlyMissing, $dryRun);
            $delta += $reprice->lastCostDelta;
            $rows[] = [$providerId, $model, $written];
        }
        $after = $dryRun ? null : $this->totals();

        $this->table(['provider', 'model', $dryRun ? 'events that would change' : 'events changed'], array_filter($rows, fn ($row) => $row[2] > 0));
        if ($dryRun) {
            $this->line(sprintf('[dry run] Total cost %.2f -> %.2f USD (nothing written).', $before['cost'], $before['cost'] + $delta));
            $this->line('Events without a cost now (before repricing): '.$before['missing']);
        } else {
            $this->line(sprintf(
                'Total cost %.2f -> %.2f USD; events without a cost %d -> %d.',
                $before['cost'],
                $after['cost'],
                $before['missing'],
                $after['missing'],
            ));
        }
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

        // Names (model + aliases) of price rows added or changed (incl. aliases) since then.
        $changed = [];
        ModelPrice::query()
            ->where('updated_at', '>=', Carbon::parse($changedSince))
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
            // Lowercased: MySQL compares names case-insensitively, so DISTINCT may
            // return a different spelling here than in $all.
            ->mapWithKeys(fn ($row) => [$row->provider_id."\0".strtolower($row->model) => true]);

        return $all
            ->filter(fn ($row) => isset($changed[$row->provider_id][strtolower($row->model)])
                || isset($missing[$row->provider_id."\0".strtolower($row->model)]))
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
