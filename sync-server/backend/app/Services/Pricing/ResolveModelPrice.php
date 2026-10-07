<?php

namespace App\Services\Pricing;

use App\Models\ModelPrice;
use Carbon\Carbon;

class ResolveModelPrice
{
    /** @var array<string, array<string, list<ModelPrice>>> */
    private array $byProviderAndName = [];

    /**
     * Find the active model price for a given provider/model/timestamp.
     */
    public function handle(string $providerId, string $model, Carbon $timestamp): ?ModelPrice
    {
        $matches = [];
        foreach ($this->rowsFor($providerId, strtolower($model)) as $price) {
            if ($price->effective_from->gt($timestamp)) {
                continue;
            }
            if ($price->effective_to !== null && ! $price->effective_to->gt($timestamp)) {
                continue;
            }
            $matches[] = $price;
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

    /**
     * @return list<ModelPrice>
     */
    private function rowsFor(string $providerId, string $needle): array
    {
        if (! array_key_exists($providerId, $this->byProviderAndName)) {
            $this->byProviderAndName[$providerId] = $this->indexProvider($providerId);
        }

        return $this->byProviderAndName[$providerId][$needle] ?? [];
    }

    /**
     * Prices are stored newest-first, so the first dated match is the current one.
     *
     * @return array<string, list<ModelPrice>>
     */
    private function indexProvider(string $providerId): array
    {
        $indexed = [];
        $prices = ModelPrice::query()
            ->where('provider_id', $providerId)
            ->orderByDesc('effective_from')
            ->get();

        foreach ($prices as $price) {
            $this->pushPrice($indexed, strtolower($price->model), $price);
            $aliases = json_decode($price->aliases_json ?? '[]', true);
            if (! is_array($aliases)) {
                continue;
            }
            foreach ($aliases as $alias) {
                if (! is_string($alias) || $alias === '') {
                    continue;
                }
                $key = strtolower($alias);
                if ($key === strtolower($price->model)) {
                    continue;
                }
                $this->pushPrice($indexed, $key, $price);
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, list<ModelPrice>>  $indexed
     */
    private function pushPrice(array &$indexed, string $key, ModelPrice $price): void
    {
        $indexed[$key][] = $price;
    }
}
