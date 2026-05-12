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
            $current = ModelPrice::where('provider_id', $providerId)
                ->where('model', $model)
                ->whereNull('effective_to')
                ->orderBy('effective_from', 'desc')
                ->first();

            $newValues = [
                'input_per_1m' => $prices['input_per_1m'] ?? null,
                'output_per_1m' => $prices['output_per_1m'] ?? null,
                'cached_input_per_1m' => $prices['cached_input_per_1m'] ?? null,
                'cache_write_per_1m' => $prices['cache_write_per_1m'] ?? null,
                'cache_read_per_1m' => $prices['cache_read_per_1m'] ?? null,
                'reasoning_per_1m' => $prices['reasoning_per_1m'] ?? null,
                'tool_per_1m' => $prices['tool_per_1m'] ?? null,
            ];

            $newAliases = isset($prices['aliases_json']) ? json_encode($prices['aliases_json']) : null;

            if ($current && $this->pricesEqual($current, $newValues)) {
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

    private function pricesEqual(ModelPrice $current, array $new): bool
    {
        $fields = [
            'input_per_1m',
            'output_per_1m',
            'cached_input_per_1m',
            'cache_write_per_1m',
            'cache_read_per_1m',
            'reasoning_per_1m',
            'tool_per_1m',
        ];

        foreach ($fields as $field) {
            $a = $current->$field !== null ? (float) $current->$field : null;
            $b = $new[$field] ?? null;
            if ($a !== $b) {
                return false;
            }
        }

        return true;
    }
}
