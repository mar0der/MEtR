<?php

namespace App\Services\Cursor;

use App\Models\ModelPrice;
use App\Models\Provider;

class CursorModelPrices
{
    public const CATALOG_VERSION = 'cursor-docs-2026-08-29';

    public const SOURCE_URL = 'https://cursor.com/docs/models-and-pricing';

    /**
     * Cursor list prices for slugs seen in dashboard CSV exports.
     * Auto is omitted: Cursor bills Auto at the routed model, which the CSV does not include.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return [
            [
                'model' => 'grok-4.6',
                'aliases' => ['cursor-grok-4.6-high', 'cursor-grok-4.6-xhigh'],
                'input_per_1m' => '2.0000000000',
                'output_per_1m' => '6.0000000000',
                'cached_input_per_1m' => '0.5000000000',
                'cache_write_per_1m' => '2.0000000000',
                'cache_read_per_1m' => '0.5000000000',
            ],
            [
                'model' => 'grok-4.6-fast',
                'aliases' => ['cursor-grok-4.6-high-fast'],
                'input_per_1m' => '4.0000000000',
                'output_per_1m' => '12.0000000000',
                'cached_input_per_1m' => '1.0000000000',
                'cache_write_per_1m' => '4.0000000000',
                'cache_read_per_1m' => '1.0000000000',
            ],
            [
                'model' => 'grok-4.5',
                'aliases' => ['cursor-grok-4.5-high', 'cursor-grok-4.5-medium'],
                'input_per_1m' => '2.0000000000',
                'output_per_1m' => '6.0000000000',
                'cached_input_per_1m' => '0.5000000000',
                'cache_write_per_1m' => '2.0000000000',
                'cache_read_per_1m' => '0.5000000000',
            ],
            [
                'model' => 'grok-4.5-fast',
                'aliases' => ['cursor-grok-4.5-high-fast', 'cursor-grok-4.5-medium-fast'],
                'input_per_1m' => '4.0000000000',
                'output_per_1m' => '18.0000000000',
                'cached_input_per_1m' => '1.0000000000',
                'cache_write_per_1m' => '4.0000000000',
                'cache_read_per_1m' => '1.0000000000',
            ],
            [
                'model' => 'composer-2.5',
                'aliases' => [],
                'input_per_1m' => '0.5000000000',
                'output_per_1m' => '2.5000000000',
                'cached_input_per_1m' => '0.2000000000',
                'cache_write_per_1m' => '0.5000000000',
                'cache_read_per_1m' => '0.2000000000',
            ],
            [
                'model' => 'composer-2.5-fast',
                'aliases' => [],
                'input_per_1m' => '3.0000000000',
                'output_per_1m' => '15.0000000000',
                'cached_input_per_1m' => '0.5000000000',
                'cache_write_per_1m' => '3.0000000000',
                'cache_read_per_1m' => '0.5000000000',
            ],
            [
                'model' => 'claude-sonnet-5',
                'aliases' => ['claude-sonnet-5-thinking-max'],
                'input_per_1m' => '2.0000000000',
                'output_per_1m' => '10.0000000000',
                'cached_input_per_1m' => '0.2000000000',
                'cache_write_per_1m' => '2.5000000000',
                'cache_read_per_1m' => '0.2000000000',
            ],
        ];
    }

    public function seed(): int
    {
        Provider::query()->updateOrCreate(
            ['id' => 'cursor'],
            ['display_name' => 'Cursor']
        );

        $effectiveFrom = '2026-06-01 00:00:00';
        $count = 0;

        foreach ($this->catalog() as $price) {
            ModelPrice::updateOrCreate(
                [
                    'provider_id' => 'cursor',
                    'model' => $price['model'],
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                ],
                [
                    'aliases_json' => json_encode($price['aliases']),
                    'currency' => 'USD',
                    'input_per_1m' => $price['input_per_1m'],
                    'output_per_1m' => $price['output_per_1m'],
                    'cached_input_per_1m' => $price['cached_input_per_1m'],
                    'cache_write_per_1m' => $price['cache_write_per_1m'],
                    'cache_read_per_1m' => $price['cache_read_per_1m'],
                    'source_url' => self::SOURCE_URL,
                    'catalog_version' => self::CATALOG_VERSION,
                    'user_override' => false,
                ]
            );
            $count++;
        }

        return $count;
    }
}
