<?php

namespace App\Console\Commands;

use App\Services\Pricing\Sources\AnthropicPricingSource;
use App\Services\Pricing\Sources\GooglePricingSource;
use App\Services\Pricing\Sources\KimiPricingSource;
use App\Services\Pricing\Sources\OpenAiPricingSource;
use App\Services\Pricing\UpdateProviderPrices;
use Illuminate\Console\Command;

class UpdateModelPrices extends Command
{
    protected $signature = 'metr:prices:update {--provider=} {--dry-run} {--force-manual-review}';

    protected $description = 'Update model prices from provider sources';

    public function handle(UpdateProviderPrices $updater): int
    {
        $provider = $this->option('provider');
        $dryRun = $this->option('dry-run');
        $forceReview = $this->option('force-manual-review');

        $sources = [
            'openai' => OpenAiPricingSource::class,
            'anthropic' => AnthropicPricingSource::class,
            'kimi' => KimiPricingSource::class,
            'google' => GooglePricingSource::class,
        ];

        if ($provider) {
            if (! isset($sources[$provider])) {
                $this->error("Unknown provider: {$provider}");

                return self::FAILURE;
            }
            $sources = [$provider => $sources[$provider]];
        }

        foreach ($sources as $key => $sourceClass) {
            $this->info("Fetching prices for: {$key}");

            $source = new $sourceClass;
            $observation = $source->observe();

            if ($forceReview) {
                $observation->update(['status' => 'manual_review']);
                $this->warn("Marked for manual review: {$key}");

                continue;
            }

            if ($observation->status === 'parse_failed') {
                $this->error("Parse failed for {$key}: {$observation->error}");

                continue;
            }

            if ($dryRun) {
                $this->info("Dry run for {$key}: ".json_encode($observation->parsed_json));

                continue;
            }

            $updater->handle($observation, $observation->parsed_json ?? []);
            $this->info("Updated prices for: {$key}");
        }

        return self::SUCCESS;
    }
}
