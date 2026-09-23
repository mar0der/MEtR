<?php

namespace App\Services\Pricing;

use App\Models\ModelPrice;
use Carbon\Carbon;

class ResolveModelPrice
{
    /**
     * Find the active model price for a given provider/model/timestamp.
     */
    public function handle(string $providerId, string $model, Carbon $timestamp): ?ModelPrice
    {
        $candidates = ModelPrice::where('provider_id', $providerId)
            ->where('effective_from', '<=', $timestamp)
            ->where(function ($q) use ($timestamp) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $timestamp);
            })
            ->orderBy('effective_from', 'desc')
            ->get();

        $matches = [];
        foreach ($candidates as $price) {
            if (strtolower($price->model) === strtolower($model)) {
                $matches[] = $price;

                continue;
            }

            $aliases = json_decode($price->aliases_json ?? '[]', true);
            foreach ($aliases as $alias) {
                if (strtolower($alias) === strtolower($model)) {
                    $matches[] = $price;
                    break;
                }
            }
        }

        if ($matches === []) {
            return null;
        }

        // A hand-entered price is only used when the catalog has no row for this model.
        // A newer two-rate manual row must not hide the catalog row that includes cache.
        $official = array_values(array_filter(
            $matches,
            fn (ModelPrice $price) => ! $price->isManual(),
        ));

        return ($official !== [] ? $official : $matches)[0];
    }
}
