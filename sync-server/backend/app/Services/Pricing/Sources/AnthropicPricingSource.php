<?php

namespace App\Services\Pricing\Sources;

class AnthropicPricingSource extends AbstractPricingSource
{
    public function getProviderId(): string
    {
        return 'anthropic';
    }

    public function getSourceUrl(): string
    {
        return 'https://docs.anthropic.com/en/docs/about-claude/pricing';
    }

    public function fetch(): array
    {
        // Placeholder: real implementation would scrape/parse the page.
        return [
            'claude-sonnet-4.5' => [
                'input_per_1m' => 3.00,
                'output_per_1m' => 15.00,
                'cache_write_per_1m' => 3.75,
                'cache_read_per_1m' => 0.30,
                'currency' => 'USD',
            ],
        ];
    }
}
