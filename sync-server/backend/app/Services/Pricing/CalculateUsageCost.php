<?php

namespace App\Services\Pricing;

use App\Models\ModelPrice;

class CalculateUsageCost
{
    /**
     * Calculate API-equivalent cost in USD.
     *
     * NOTE: Keep in sync with src-tauri/src/lib.rs calculate_cost()
     * Token semantics:
     *   - Anthropic/Claude: input_tokens is ALREADY uncached (cache_read/cache_write are separate)
     *   - OpenAI/Kimi/Gemini/DeepSeek: input_tokens INCLUDES cached subset → subtract it
     *
     * @param  array<string, int>  $tokens
     * @return array{cost: float|null, pricing_match_confidence: string}
     */
    public function handle(ModelPrice $price, array $tokens, string $providerId = ''): array
    {
        $cost = 0.0;
        $missingRate = false;

        // Heuristic: if cache_read/cache_write > 0, assume Anthropic-style (input is uncached).
        // Otherwise, if cached_input > 0, subtract it from input (OpenAI-style).
        $hasAnthropicStyleCache = ($tokens['cache_read'] ?? 0) > 0 || ($tokens['cache_write'] ?? 0) > 0;
        $effectiveInput = (! $hasAnthropicStyleCache && ($tokens['cached_input'] ?? 0) > 0)
            ? max(0, ($tokens['input'] ?? 0) - ($tokens['cached_input'] ?? 0))
            : ($tokens['input'] ?? 0);

        $cost += $this->calculateCategory($effectiveInput, $price->input_per_1m, $missingRate);
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
