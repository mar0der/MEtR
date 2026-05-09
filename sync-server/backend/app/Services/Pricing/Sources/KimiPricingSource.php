<?php

namespace App\Services\Pricing\Sources;

class KimiPricingSource extends AbstractPricingSource
{
    public function getProviderId(): string
    {
        return 'kimi';
    }

    public function getSourceUrl(): string
    {
        return 'https://platform.moonshot.cn/';
    }

    public function fetch(): array
    {
        // Placeholder
        return [
            'kimi-k2.6' => [
                'input_per_1m' => 2.00,
                'output_per_1m' => 8.00,
                'currency' => 'USD',
            ],
        ];
    }
}
