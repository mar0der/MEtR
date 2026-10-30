<?php

namespace App\Services\Pricing;

use App\Models\UsageEvent;

class RepriceModelUsage
{
    public function __construct(
        private ResolveModelPrice $resolveModelPrice,
        private CalculateUsageCost $calculateUsageCost,
    ) {}

    /** Cost change (USD) of the rows counted by the last handle() call. */
    public float $lastCostDelta = 0.0;

    /**
     * Reprices one provider/model with the current catalog. Writes only rows
     * whose cost or price match changed; returns the number of rows written.
     *
     * $estimateOneHour: null keeps each event's own choice (an unreported
     * 1-hour cache split stays estimated until a client reports it); true or
     * false sets it, for metr:prices:estimate-1h-cache. $scope narrows the
     * events further.
     *
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder): mixed)|null  $scope
     */
    public function handle(
        string $providerId,
        string $model,
        bool $onlyMissing = false,
        bool $dryRun = false,
        ?\Closure $scope = null,
        ?bool $estimateOneHour = null,
    ): int {
        $updated = 0;
        $this->lastCostDelta = 0.0;

        UsageEvent::query()
            ->where('provider_id', $providerId)
            ->where('model', $model)
            ->when($onlyMissing, fn ($query) => $query->whereNull('official_api_cost_usd'))
            ->when($scope !== null, $scope)
            ->orderBy('id')
            ->chunkById(500, function ($events) use (&$updated, $dryRun, $estimateOneHour) {
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
                        'cache_write_1h' => (int) ($event->cache_write_1h_tokens ?? 0),
                        'cache_read' => (int) $event->cache_read_tokens,
                        'reasoning' => (int) $event->reasoning_tokens,
                        'tool' => (int) $event->tool_tokens,
                        'unknown' => (int) $event->unknown_tokens,
                    ];
                    $estimate = $estimateOneHour ?? $event->hasUnreportedOneHourSplit();
                    $costData = $price
                        ? $this->calculateUsageCost->handle($price, $tokens, $event->provider_id, $estimate)
                        : ['cost' => null, 'pricing_match_confidence' => 'missing'];

                    // Rewritten only when the flag changes (MySQL re-formats stored JSON).
                    if ($estimateOneHour !== null && $event->hasUnreportedOneHourSplit() !== $estimateOneHour) {
                        $event->warnings_json = UsageEvent::warningsWithUnreportedSplit($event->warnings_json, $estimateOneHour);
                    }
                    $event->fill([
                        'official_api_cost_usd' => $costData['cost'],
                        'model_price_id' => $price?->id,
                        'pricing_match_confidence' => $costData['pricing_match_confidence'],
                    ]);
                    if ($event->isDirty()) {
                        $this->lastCostDelta += (float) $event->official_api_cost_usd
                            - (float) $event->getOriginal('official_api_cost_usd');
                        if (! $dryRun) {
                            $event->save();
                        }
                        $updated++;
                    }
                }
            });

        return $updated;
    }
}
