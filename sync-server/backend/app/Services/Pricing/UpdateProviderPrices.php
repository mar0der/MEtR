<?php

namespace App\Services\Pricing;

use App\Models\ModelPrice;
use App\Models\PriceObservation;
use Illuminate\Support\Facades\Log;

class UpdateProviderPrices
{
    public function __construct(
        private ResolveModelPrice $resolveModelPrice,
    ) {}

    /**
     * Process a parsed price observation and mutate model_prices if changed.
     *
     * @param  array<string, array{input_per_1m: float|null, output_per_1m: float|null, ...}>  $parsedPrices
     */
    public function handle(PriceObservation $observation, array $parsedPrices): void
    {
        $this->processPrices($observation, $observation->provider_id, $parsedPrices);
    }

    public function handleMultiProvider(PriceObservation $observation, string $providerId, array $parsedPrices, ?string $effectiveFrom = null): void
    {
        $this->processPrices($observation, $providerId, $parsedPrices, $effectiveFrom);
    }

    private function processPrices(PriceObservation $observation, string $providerId, array $parsedPrices, ?string $effectiveFrom = null): void
    {
        foreach ($parsedPrices as $model => $prices) {
            // Catalog rows all start 2025-01-01, so the newest row is the current one.
            $open = ModelPrice::where('provider_id', $providerId)
                ->where('model', $model)
                ->whereNull('effective_to')
                ->where('user_override', false)
                ->where(function ($query) {
                    $query->whereNull('catalog_version')
                        ->orWhere('catalog_version', '!=', 'user');
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                // MySQL matches names case-insensitively; another spelling is another model.
                ->filter(fn (ModelPrice $price) => $price->model === $model)
                ->values();
            $current = $open->first();

            $newValues = [];
            foreach (self::RATE_FIELDS as $field) {
                $newValues[$field] = isset($prices[$field]) ? round((float) $prices[$field], 6) : null;
            }

            $newAliases = isset($prices['aliases_json']) ? json_encode($prices['aliases_json']) : null;

            // Only one open catalog row per model; older open duplicates are closed.
            // They never set a price (the newest row wins), so updated_at stays
            // and metr:prices:reprice --changed-since does not pick them up.
            if ($open->count() > 1) {
                ModelPrice::whereIn('id', $open->slice(1)->pluck('id'))
                    ->toBase()
                    ->update(['effective_to' => $observation->fetched_at]);
            }

            if ($current && self::pricesEqual($current, $newValues)) {
                // Prices unchanged — update aliases in place if they differ
                $currentAliases = $current->aliases_json ?? '[]';
                if ($newAliases !== null && $currentAliases !== $newAliases) {
                    $current->update(['aliases_json' => $newAliases]);
                    Log::info('Updated model aliases', [
                        'provider_id' => $providerId,
                        'model' => $model,
                        'old_aliases' => $currentAliases,
                        'new_aliases' => $newAliases,
                    ]);
                }
                continue;
            }

            if ($current) {
                $current->update(['effective_to' => $observation->fetched_at]);
            }

            ModelPrice::create(array_merge($newValues, [
                'provider_id' => $providerId,
                'model' => $model,
                'aliases_json' => $newAliases,
                'currency' => $prices['currency'] ?? 'USD',
                'effective_from' => $effectiveFrom ?? $observation->fetched_at,
                'effective_to' => null,
                'source_url' => $observation->source_url,
                'source_hash' => $observation->source_hash,
                'catalog_version' => $observation->source_hash,
            ]));

            Log::info('Updated model price', [
                'provider_id' => $providerId,
                'model' => $model,
                'observation_id' => $observation->id,
            ]);
        }
    }

    public const RATE_FIELDS = [
        'input_per_1m',
        'output_per_1m',
        'cached_input_per_1m',
        'cache_write_per_1m',
        'cache_read_per_1m',
        'reasoning_per_1m',
        'tool_per_1m',
    ];

    /** Rates are compared at the stored precision, with a float tolerance. */
    public static function pricesEqual(ModelPrice $current, array $new): bool
    {
        foreach (self::RATE_FIELDS as $field) {
            $a = $current->$field;
            $b = $new[$field] ?? null;
            if ($a === null || $b === null) {
                if ($a !== $b) {
                    return false;
                }

                continue;
            }
            if (abs(round((float) $a, 6) - round((float) $b, 6)) >= 1e-9) {
                return false;
            }
        }

        return true;
    }
}
