<?php

namespace App\Services\Pricing\Sources;

class GooglePricingSource extends AbstractPricingSource
{
    public function getProviderId(): string
    {
        return 'google';
    }

    public function getSourceUrl(): string
    {
        return 'https://ai.google.dev/gemini-api/docs/pricing';
    }

    public function fetch(): array
    {
        // Placeholder
        return [
            'gemini-2.5-pro' => [
                'input_per_1m' => 1.25,
                'output_per_1m' => 10.00,
                'currency' => 'USD',
            ],
        ];
    }
}
