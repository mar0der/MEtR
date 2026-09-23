<?php

namespace App\Console\Commands;

use App\Models\ModelPrice;
use App\Services\Pricing\RepriceModelUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DropManualPrices extends Command
{
    protected $signature = 'metr:prices:drop-manual';

    protected $description = 'Delete hand-entered prices when a catalog price exists, then reprice those models.';

    public function handle(RepriceModelUsage $reprice): int
    {
        $manual = ModelPrice::query()
            ->where(function ($query) {
                $query->where('user_override', true)
                    ->orWhere('catalog_version', 'user');
            })
            ->get();

        $dropped = 0;
        $models = [];

        foreach ($manual as $price) {
            $hasCatalog = ModelPrice::query()
                ->where('provider_id', $price->provider_id)
                ->where('model', $price->model)
                ->where('id', '!=', $price->id)
                ->where('user_override', false)
                ->where(function ($query) {
                    $query->whereNull('catalog_version')
                        ->orWhere('catalog_version', '!=', 'user');
                })
                ->exists();

            if (! $hasCatalog) {
                $this->line("Kept {$price->provider_id}/{$price->model} ({$price->id}); no catalog price.");

                continue;
            }

            $models[$price->provider_id.'|'.$price->model] = [$price->provider_id, $price->model];
            $price->delete();
            $dropped++;
            $this->line("Deleted {$price->provider_id}/{$price->model} ({$price->id}).");
        }

        $repriced = 0;
        foreach ($models as [$providerId, $model]) {
            $count = $reprice->handle($providerId, $model);
            $repriced += $count;
            $this->line("Repriced {$count} {$providerId}/{$model} events.");
        }

        Cache::forget('pricing:catalog');
        $this->info("Deleted {$dropped} manual prices. Repriced {$repriced} events.");

        return self::SUCCESS;
    }
}
