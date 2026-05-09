<?php

namespace App\Services\Pricing\Sources;

class OpenAiPricingSource extends AbstractPricingSource
{
    public function getProviderId(): string
    {
        return 'openai';
    }

    public function getSourceUrl(): string
    {
        return 'https://openai.com/api/pricing/';
    }

    public function fetch(): array
    {
        // Placeholder: real implementation would scrape/parse the page.
        return [
            'gpt-5.1' => [
                'input_per_1m' => 2.00,
                'output_per_1m' => 8.00,
                'cached_input_per_1m' => 1.00,
                'currency' => 'USD',
            ],
            'gpt-5.1-mini' => [
                'input_per_1m' => 0.50,
                'output_per_1m' => 2.00,
                'cached_input_per_1m' => 0.25,
                'currency' => 'USD',
            ],
        ];
    }
}
