<?php

namespace App\Services\Pricing;

use App\Models\ModelPrice;

class CalculateUsageCost
{
    /**
     * Calculate API-equivalent cost in USD.
     *
     * @param  array<string, int>  $tokens
     * @return array{cost: float|null, pricing_match_confidence: string}
     */
    public function handle(ModelPrice $price, array $tokens): array
    {
        $cost = 0.0;
        $missingRate = false;

        $cost += $this->calculateCategory($tokens['input'] ?? 0, $price->input_per_1m, $missingRate);
        $cost += $this->calculateCategory($tokens['output'] ?? 0, $price->output_per_1m, $missingRate);
        $cost += $this->calculateCategory($tokens['cached_input'] ?? 0, $price->cached_input_per_1m ?? $price->input_per_1m, $missingRate);
        $cost += $this->calculateCategory($tokens['cache_write'] ?? 0, $price->cache_write_per_1m ?? $price->input_per_1m, $missingRate);
        $cost += $this->calculateCategory($tokens['cache_read'] ?? 0, $price->cache_read_per_1m ?? $price->cached_input_per_1m, $missingRate);
        $cost += $this->calculateCategory($tokens['reasoning'] ?? 0, $price->reasoning_per_1m ?? $price->output_per_1m, $missingRate);
        $cost += $this->calculateCategory($tokens['tool'] ?? 0, $price->tool_per_1m ?? $price->input_per_1m, $missingRate);

        $unknown = $tokens['unknown'] ?? 0;
        if ($unknown > 0) {
            // Unknown tokens make cost null because we cannot accurately price them
            return [
                'cost' => null,
                'pricing_match_confidence' => 'missing',
            ];
        }

        if ($missingRate) {
            return [
                'cost' => null,
                'pricing_match_confidence' => 'missing_price',
            ];
        }

        return [
            'cost' => round($cost, 10),
            'pricing_match_confidence' => 'exact',
        ];
    }

    private function calculateCategory(int $tokens, ?string $pricePer1m, bool &$missingRate): float
    {
        if ($tokens <= 0) {
            return 0.0;
        }

        if ($pricePer1m === null) {
            $missingRate = true;

            return 0.0;
        }

        return $tokens / 1_000_000 * (float) $pricePer1m;
    }
}
