<?php

namespace Database\Seeders;

use App\Models\ModelPrice;
use Illuminate\Database\Seeder;

class ModelPriceSeeder extends Seeder
{
    public function run(): void
    {
        $effectiveFrom = '2026-05-08 00:00:00';

        foreach ($this->prices() as $price) {
            $attributes = $price;
            unset($attributes['aliases']);

            ModelPrice::updateOrCreate(
                [
                    'provider_id' => $price['provider_id'],
                    'model' => $price['model'],
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                ],
                array_merge($attributes, [
                    'aliases_json' => json_encode($price['aliases'] ?? []),
                    'currency' => 'USD',
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                    'catalog_version' => 'seed-2026-05-08',
                    'user_override' => false,
                ])
            );
        }
    }

    private function prices(): array
    {
        return [
            [
                'provider_id' => 'openai',
                'model' => 'gpt-5.5',
                'aliases' => [],
                'input_per_1m' => '5.0000000000',
                'output_per_1m' => '30.0000000000',
                'cached_input_per_1m' => '0.5000000000',
                'source_url' => 'https://openai.com/api/pricing/',
            ],
            [
                'provider_id' => 'openai',
                'model' => 'gpt-5.4',
                'aliases' => [],
                'input_per_1m' => '2.5000000000',
                'output_per_1m' => '15.0000000000',
                'cached_input_per_1m' => '0.2500000000',
                'source_url' => 'https://openai.com/api/pricing/',
            ],
            [
                'provider_id' => 'openai',
                'model' => 'gpt-5.3-codex',
                'aliases' => [],
                'input_per_1m' => '1.7500000000',
                'output_per_1m' => '14.0000000000',
                'cached_input_per_1m' => '0.1750000000',
                'source_url' => 'https://developers.openai.com/api/docs/models/gpt-5.3-codex',
            ],
            [
                'provider_id' => 'openai',
                'model' => 'gpt-5.4-mini',
                'aliases' => [],
                'input_per_1m' => '0.7500000000',
                'output_per_1m' => '4.5000000000',
                'cached_input_per_1m' => '0.0750000000',
                'source_url' => 'https://openai.com/api/pricing/',
            ],
            [
                'provider_id' => 'openai',
                'model' => 'gpt-5.1',
                'aliases' => ['gpt-5.1-codex'],
                'input_per_1m' => '1.2500000000',
                'output_per_1m' => '10.0000000000',
                'cached_input_per_1m' => '0.1250000000',
                'source_url' => 'https://openai.com/api/pricing/',
            ],
            [
                'provider_id' => 'openai',
                'model' => 'gpt-5.1-mini',
                'aliases' => ['gpt-5.1-mini-codex'],
                'input_per_1m' => '0.2500000000',
                'output_per_1m' => '2.0000000000',
                'cached_input_per_1m' => '0.0250000000',
                'source_url' => 'https://openai.com/api/pricing/',
            ],
            [
                'provider_id' => 'anthropic',
                'model' => 'claude-sonnet-4-5-20250929',
                'aliases' => ['claude-sonnet-4-5', 'claude-sonnet-4.5'],
                'input_per_1m' => '3.0000000000',
                'output_per_1m' => '15.0000000000',
                'cache_write_per_1m' => '6.0000000000',
                'cache_read_per_1m' => '0.3000000000',
                'source_url' => 'https://docs.anthropic.com/en/docs/about-claude/pricing',
            ],
            [
                'provider_id' => 'anthropic',
                'model' => 'claude-sonnet-4-6',
                'aliases' => ['claude-sonnet-4.6'],
                'input_per_1m' => '3.0000000000',
                'output_per_1m' => '15.0000000000',
                'cache_write_per_1m' => '6.0000000000',
                'cache_read_per_1m' => '0.3000000000',
                'source_url' => 'https://docs.anthropic.com/en/docs/about-claude/pricing',
            ],
            [
                'provider_id' => 'anthropic',
                'model' => 'claude-haiku-4-5-20251001',
                'aliases' => ['claude-haiku-4-5', 'claude-haiku-4.5', 'haiku'],
                'input_per_1m' => '1.0000000000',
                'output_per_1m' => '5.0000000000',
                'cache_write_per_1m' => '2.0000000000',
                'cache_read_per_1m' => '0.1000000000',
                'source_url' => 'https://docs.anthropic.com/en/docs/about-claude/pricing',
            ],
            [
                'provider_id' => 'anthropic',
                'model' => 'claude-opus-4-5-20251101',
                'aliases' => ['claude-opus-4-5', 'claude-opus-4.5'],
                'input_per_1m' => '5.0000000000',
                'output_per_1m' => '25.0000000000',
                'cache_write_per_1m' => '10.0000000000',
                'cache_read_per_1m' => '0.5000000000',
                'source_url' => 'https://docs.anthropic.com/en/docs/about-claude/pricing',
            ],
            [
                'provider_id' => 'kimi',
                'model' => 'kimi-k2.6',
                'aliases' => ['kimi-k2.6:cloud', 'kimi-for-coding', 'kimi-code/kimi-for-coding', 'Kimi-k2.6'],
                'input_per_1m' => '0.9500000000',
                'output_per_1m' => '4.0000000000',
                'cached_input_per_1m' => '0.1600000000',
                'cache_write_per_1m' => '0.9500000000',
                'cache_read_per_1m' => '0.1600000000',
                'source_url' => 'https://www.kimi.com/resources/kimi-k2-6-pricing',
            ],
            [
                'provider_id' => 'google',
                'model' => 'gemini-2.5-pro',
                'aliases' => [],
                'input_per_1m' => '1.2500000000',
                'output_per_1m' => '10.0000000000',
                'cached_input_per_1m' => '0.3100000000',
                'source_url' => 'https://ai.google.dev/gemini-api/docs/pricing',
            ],
        ];
    }
}
