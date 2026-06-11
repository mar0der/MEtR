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
                'provider_id' => 'anthropic',
                'model' => 'claude-fable-5',
                'aliases' => [],
                'input_per_1m' => '10.0000000000',
                'output_per_1m' => '50.0000000000',
                'cache_write_per_1m' => '12.5000000000',
                'cache_read_per_1m' => '1.0000000000',
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
            // Older OpenAI models
            [
                'provider_id' => 'openai',
                'model' => 'gpt-4o',
                'aliases' => ['gpt-4o-2024-05-13', 'gpt-4o-2024-08-06', 'gpt-4o-2024-11-20'],
                'input_per_1m' => '2.5000000000',
                'output_per_1m' => '10.0000000000',
                'cached_input_per_1m' => '1.2500000000',
                'source_url' => 'https://openai.com/api/pricing/',
            ],
            [
                'provider_id' => 'openai',
                'model' => 'gpt-4o-mini',
                'aliases' => ['gpt-4o-mini-2024-07-18'],
                'input_per_1m' => '0.1500000000',
                'output_per_1m' => '0.6000000000',
                'cached_input_per_1m' => '0.0750000000',
                'source_url' => 'https://openai.com/api/pricing/',
            ],
            [
                'provider_id' => 'openai',
                'model' => 'gpt-4-turbo',
                'aliases' => ['gpt-4-turbo-2024-04-09', 'gpt-4-turbo-preview'],
                'input_per_1m' => '10.0000000000',
                'output_per_1m' => '30.0000000000',
                'cached_input_per_1m' => '5.0000000000',
                'source_url' => 'https://openai.com/api/pricing/',
            ],
            [
                'provider_id' => 'openai',
                'model' => 'gpt-4',
                'aliases' => ['gpt-4-0613', 'gpt-4-0314', 'gpt-4-0125-preview', 'gpt-4-1106-preview'],
                'input_per_1m' => '30.0000000000',
                'output_per_1m' => '60.0000000000',
                'cached_input_per_1m' => '15.0000000000',
                'source_url' => 'https://openai.com/api/pricing/',
            ],
            [
                'provider_id' => 'openai',
                'model' => 'gpt-3.5-turbo',
                'aliases' => ['gpt-3.5-turbo-0125', 'gpt-3.5-turbo-1106', 'gpt-3.5-turbo-0613'],
                'input_per_1m' => '0.5000000000',
                'output_per_1m' => '1.5000000000',
                'cached_input_per_1m' => '0.2500000000',
                'source_url' => 'https://openai.com/api/pricing/',
            ],
            // Older Anthropic models
            [
                'provider_id' => 'anthropic',
                'model' => 'claude-3-5-sonnet',
                'aliases' => ['claude-3-5-sonnet-20241022', 'claude-3-5-sonnet-20240620'],
                'input_per_1m' => '3.0000000000',
                'output_per_1m' => '15.0000000000',
                'cache_write_per_1m' => '3.7500000000',
                'cache_read_per_1m' => '0.3000000000',
                'source_url' => 'https://docs.anthropic.com/en/docs/about-claude/pricing',
            ],
            [
                'provider_id' => 'anthropic',
                'model' => 'claude-3-opus',
                'aliases' => ['claude-3-opus-20240229'],
                'input_per_1m' => '15.0000000000',
                'output_per_1m' => '75.0000000000',
                'cache_write_per_1m' => '18.7500000000',
                'cache_read_per_1m' => '1.5000000000',
                'source_url' => 'https://docs.anthropic.com/en/docs/about-claude/pricing',
            ],
            [
                'provider_id' => 'anthropic',
                'model' => 'claude-3-sonnet',
                'aliases' => ['claude-3-sonnet-20240229'],
                'input_per_1m' => '3.0000000000',
                'output_per_1m' => '15.0000000000',
                'cache_write_per_1m' => '3.7500000000',
                'cache_read_per_1m' => '0.3000000000',
                'source_url' => 'https://docs.anthropic.com/en/docs/about-claude/pricing',
            ],
            [
                'provider_id' => 'anthropic',
                'model' => 'claude-3-haiku',
                'aliases' => ['claude-3-haiku-20240307'],
                'input_per_1m' => '0.2500000000',
                'output_per_1m' => '1.2500000000',
                'cache_write_per_1m' => '0.3125000000',
                'cache_read_per_1m' => '0.0250000000',
                'source_url' => 'https://docs.anthropic.com/en/docs/about-claude/pricing',
            ],
            // Older Google models
            [
                'provider_id' => 'google',
                'model' => 'gemini-1.5-pro',
                'aliases' => ['gemini-1.5-pro-002', 'gemini-1.5-pro-001'],
                'input_per_1m' => '1.2500000000',
                'output_per_1m' => '5.0000000000',
                'cached_input_per_1m' => '0.3100000000',
                'source_url' => 'https://ai.google.dev/gemini-api/docs/pricing',
            ],
            [
                'provider_id' => 'google',
                'model' => 'gemini-1.5-flash',
                'aliases' => ['gemini-1.5-flash-002', 'gemini-1.5-flash-001'],
                'input_per_1m' => '0.0750000000',
                'output_per_1m' => '0.3000000000',
                'cached_input_per_1m' => '0.0187500000',
                'source_url' => 'https://ai.google.dev/gemini-api/docs/pricing',
            ],
        ];
    }
}
