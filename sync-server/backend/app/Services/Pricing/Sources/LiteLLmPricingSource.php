<?php

namespace App\Services\Pricing\Sources;

use GuzzleHttp\Client;

class LiteLLmPricingSource extends AbstractPricingSource
{
    private const URL = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    /**
     * Providers we care about. LiteLLM uses these provider keys.
     */
    private const ALLOWED_PROVIDERS = ['openai', 'anthropic', 'moonshot'];

    /**
     * Map LiteLLM provider → our internal provider_id.
     */
    private const PROVIDER_MAP = [
        'openai' => 'openai',
        'anthropic' => 'anthropic',
        'moonshot' => 'kimi',
    ];

    public function getProviderId(): string
    {
        return 'litellm';
    }

    public function getSourceUrl(): string
    {
        return self::URL;
    }

    public function fetch(): array
    {
        $response = $this->http->get(self::URL);
        $json = json_decode((string) $response->getBody(), true);

        if (! is_array($json)) {
            return [];
        }

        $prices = [];

        foreach ($json as $key => $entry) {
            $provider = $entry['litellm_provider'] ?? null;

            if (! in_array($provider, self::ALLOWED_PROVIDERS, true)) {
                continue;
            }

            $input = $entry['input_cost_per_token'] ?? null;
            $output = $entry['output_cost_per_token'] ?? null;

            if ($input === null || $output === null) {
                continue;
            }

            $model = $this->normalizeModelName($key, $provider);

            // Aggressive filtering: only current-generation models we care about
            if (! $this->isRelevantModel($model, $provider)) {
                continue;
            }

            // Skip duplicate model names after normalization
            if (isset($prices[$model])) {
                continue;
            }

            $prices[$model] = [
                'provider_id' => self::PROVIDER_MAP[$provider],
                'model' => $model,
                'input_per_1m' => (float) $input * 1_000_000,
                'output_per_1m' => (float) $output * 1_000_000,
                'cached_input_per_1m' => $this->extractCachedInput($entry),
                'currency' => 'USD',
                'aliases_json' => $this->buildAliases($model, $provider),
            ];
        }

        return $prices;
    }

    private function isRelevantModel(string $model, string $provider): bool
    {
        // OpenAI: only gpt-5.x family (skip gpt-4, embeddings, audio, image, etc.)
        if ($provider === 'openai') {
            if (preg_match('/^gpt-5(\.|$)/', $model)) {
                return true;
            }
            return false;
        }

        // Anthropic: only claude-*-4-* current gen (skip claude-3, claude-4-sonnet without version, etc.)
        if ($provider === 'anthropic') {
            if (preg_match('/^claude-(sonnet|opus|haiku)-4-/', $model)) {
                return true;
            }
            return false;
        }

        // Moonshot/Kimi: only kimi-k2.x family
        if ($provider === 'moonshot') {
            if (preg_match('/^kimi-k2/', $model)) {
                return true;
            }
            return false;
        }

        return false;
    }

    private function normalizeModelName(string $key, string $provider): string
    {
        // Strip provider prefix for moonshot models
        if ($provider === 'moonshot' && str_starts_with($key, 'moonshot/')) {
            $key = substr($key, strlen('moonshot/'));
        }

        // Strip date suffixes like -20260416, -2025-11-13, -2026-04-23
        $key = preg_replace('/-(\d{8}|\d{4}-\d{2}-\d{2})$/', '', $key);

        // Strip -latest suffixes
        $key = preg_replace('/-latest$/', '', $key);

        return $key;
    }

    private function extractCachedInput(array $entry): ?float
    {
        $cached = $entry['cache_read_input_token_cost'] ?? $entry['cached_input_cost_per_token'] ?? null;

        return $cached !== null ? (float) $cached * 1_000_000 : null;
    }

    /**
     * Build sensible aliases so variant model names from logs match.
     */
    private function buildAliases(string $model, string $provider): array
    {
        $aliases = [];

        if ($provider === 'openai') {
            // gpt-5.1 → gpt-5.1-codex, gpt-5.1-codex-max
            if (preg_match('/^gpt-(\d+\.\d+)$/', $model, $m)) {
                $aliases[] = "gpt-{$m[1]}-codex";
                $aliases[] = "gpt-{$m[1]}-codex-max";
            }
            // gpt-5.1-mini → gpt-5.1-codex-mini, gpt-5.1-mini-codex
            if (preg_match('/^gpt-(\d+\.\d+)-mini$/', $model, $m)) {
                $aliases[] = "gpt-{$m[1]}-codex-mini";
                $aliases[] = "gpt-{$m[1]}-mini-codex";
            }
            // gpt-5 → gpt-5-codex
            if (preg_match('/^gpt-5$/', $model)) {
                $aliases[] = 'gpt-5-codex';
                $aliases[] = 'codex';
            }
        }

        if ($provider === 'moonshot') {
            // kimi-k2.6 → kimi-for-coding
            if ($model === 'kimi-k2.6') {
                $aliases[] = 'kimi-for-coding';
                $aliases[] = 'kimi-code/kimi-for-coding';
                $aliases[] = 'Kimi-k2.6';
                $aliases[] = 'kimi-k2.6:cloud';
            }
        }

        if ($provider === 'anthropic') {
            // Strip date suffix aliases
            if (preg_match('/^(claude-[a-z]+-\d+-\d+)-\d{8}$/', $model, $m)) {
                $aliases[] = $m[1];
            }
            // Dot variant
            if (preg_match('/^(claude-[a-z]+)-(\d+)-(\d+)$/', $model, $m)) {
                $aliases[] = "{$m[1]}-{$m[2]}.{$m[3]}";
            }
        }

        return $aliases;
    }
}
