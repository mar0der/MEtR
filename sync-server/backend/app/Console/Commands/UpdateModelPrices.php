<?php

namespace App\Console\Commands;

use App\Services\Pricing\Sources\LiteLLmPricingSource;
use App\Services\Pricing\UpdateProviderPrices;
use Illuminate\Console\Command;

class UpdateModelPrices extends Command
{
    protected $signature = 'metr:prices:update {--source=} {--dry-run} {--force-manual-review}';

    protected $description = 'Update model prices from external sources';

    public function handle(UpdateProviderPrices $updater): int
    {
        $source = $this->option('source');
        $dryRun = $this->option('dry-run');
        $forceReview = $this->option('force-manual-review');

        $sourceClass = match ($source) {
            null, 'litellm' => LiteLLmPricingSource::class,
            default => null,
        };

        if ($sourceClass === null) {
            $this->error("Unknown source: {$source}. Use 'litellm'.");

            return self::FAILURE;
        }

        $this->info('Fetching prices from LiteLLM...');

        $pricingSource = new $sourceClass;
        $observation = $pricingSource->observe();

        if ($forceReview) {
            $observation->update(['status' => 'manual_review']);
            $this->warn('Marked for manual review.');

            return self::SUCCESS;
        }

        if ($observation->status === 'parse_failed') {
            $this->error("Parse failed: {$observation->error}");

            return self::FAILURE;
        }

        $parsed = $observation->parsed_json ?? [];

        if ($dryRun) {
            $this->info('Dry run - '.count($parsed).' models found:');
            foreach (array_slice($parsed, 0, 20) as $model => $price) {
                $this->line("  {$model}: \${$price['input_per_1m']} / \${$price['output_per_1m']}");
            }
            if (count($parsed) > 20) {
                $this->line('  ... and '.(count($parsed) - 20).' more');
            }

            return self::SUCCESS;
        }

        // LiteLLM returns prices keyed by model, each with its own provider_id.
        // Group by provider so UpdateProviderPrices can handle them correctly.
        $grouped = [];
        foreach ($parsed as $model => $price) {
            $providerId = $price['provider_id'];
            unset($price['provider_id']);
            $grouped[$providerId][$model] = $price;
        }

        foreach ($grouped as $providerId => $prices) {
            $this->info("Updating {$providerId}: ".count($prices).' model(s)');
            $updater->handleMultiProvider($observation, $providerId, $prices, '2025-01-01');
        }

        $this->info('Done. Total models: '.count($parsed));

        return self::SUCCESS;
    }
}
