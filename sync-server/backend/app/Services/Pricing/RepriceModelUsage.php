<?php

namespace App\Services\Pricing;

use App\Models\UsageEvent;

class RepriceModelUsage
{
    public function __construct(
        private ResolveModelPrice $resolveModelPrice,
        private CalculateUsageCost $calculateUsageCost,
    ) {}

    public function handle(string $providerId, string $model): int
    {
        $updated = 0;

        UsageEvent::query()
            ->where('provider_id', $providerId)
            ->where('model', $model)
            ->orderBy('id')
            ->chunkById(500, function ($events) use (&$updated) {
                foreach ($events as $event) {
                    $price = $this->resolveModelPrice->handle(
                        $event->provider_id,
                        $event->model,
                        $event->timestamp,
                    );
                    $tokens = [
                        'input' => (int) $event->input_tokens,
                        'output' => (int) $event->output_tokens,
                        'cached_input' => (int) $event->cached_input_tokens,
                        'cache_write' => (int) $event->cache_write_tokens,
                        'cache_read' => (int) $event->cache_read_tokens,
                        'reasoning' => (int) $event->reasoning_tokens,
                        'tool' => (int) $event->tool_tokens,
                        'unknown' => (int) $event->unknown_tokens,
                    ];
                    $costData = $price
                        ? $this->calculateUsageCost->handle($price, $tokens, $event->provider_id)
                        : ['cost' => null, 'pricing_match_confidence' => 'missing'];

                    $event->update([
                        'official_api_cost_usd' => $costData['cost'],
                        'model_price_id' => $price?->id,
                        'pricing_match_confidence' => $costData['pricing_match_confidence'],
                    ]);
                    $updated++;
                }
            });

        return $updated;
    }
}
