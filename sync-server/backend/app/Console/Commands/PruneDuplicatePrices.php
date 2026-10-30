<?php

namespace App\Console\Commands;

use App\Console\PriceJobLock;
use App\Models\ModelPrice;
use App\Services\Pricing\UpdateProviderPrices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes catalog price rows that only repeat the model's current row: same
 * rates, aliases, currency and start date, closed, and used by no event.
 * Such rows never decide a price (the newer current row always wins), so
 * deleting them changes no cost. Only rows older than the current one go. Back up model_prices before running it.
 */
class PruneDuplicatePrices extends Command
{
    protected $signature = 'metr:prices:prune-duplicates {--dry-run : Count only, delete nothing}';

    protected $description = 'Delete unused catalog price rows that duplicate the current row';

    public function handle(): int
    {
        return $this->option('dry-run')
            ? $this->prune(true)
            : PriceJobLock::run($this, fn () => $this->prune(false));
    }

    private function prune(bool $dryRun): int
    {
        $catalog = fn () => ModelPrice::query()
            ->where('user_override', false)
            ->where(fn ($q) => $q->whereNull('catalog_version')->orWhere('catalog_version', '!=', 'user'));

        $current = $catalog()->whereNull('effective_to')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->unique(fn (ModelPrice $p) => $p->provider_id."\0".strtolower($p->model));

        $total = 0;
        foreach ($current as $keep) {
            $ids = $catalog()
                ->where('provider_id', $keep->provider_id)
                ->where('model', $keep->model)
                ->where('id', '!=', $keep->id)
                ->whereNotNull('effective_to')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('usage_events')->whereColumn('usage_events.model_price_id', 'model_prices.id'))
                ->get()
                // Older than the kept row, so it always ranked below it.
                ->filter(fn (ModelPrice $p) => $p->model === $keep->model
                    && $p->created_at !== null && $keep->created_at !== null
                    && ($p->created_at->lt($keep->created_at) || ($p->created_at->equalTo($keep->created_at) && strcmp($p->id, $keep->id) < 0))
                    && $p->currency === $keep->currency
                    && $p->effective_from->equalTo($keep->effective_from)
                    && ($p->aliases_json ?? '[]') === ($keep->aliases_json ?? '[]')
                    && UpdateProviderPrices::pricesEqual($p, $keep->only(UpdateProviderPrices::RATE_FIELDS)))
                ->pluck('id');

            if ($ids->isNotEmpty() && ! $dryRun) {
                $ids->chunk(500)->each(fn ($chunk) => ModelPrice::whereIn('id', $chunk)->delete());
            }
            $total += $ids->count();
        }

        $this->line(sprintf('%s %d duplicate price rows (of %d).', $dryRun ? 'Would delete' : 'Deleted', $total, ModelPrice::count()));

        return self::SUCCESS;
    }
}
