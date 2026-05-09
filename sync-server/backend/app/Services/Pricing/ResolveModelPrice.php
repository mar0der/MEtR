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

        foreach ($candidates as $price) {
            if (strtolower($price->model) === strtolower($model)) {
                return $price;
            }

            $aliases = json_decode($price->aliases_json ?? '[]', true);
            foreach ($aliases as $alias) {
                if (strtolower($alias) === strtolower($model)) {
                    return $price;
                }
            }
        }

        return null;
    }
}
