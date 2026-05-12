<?php

namespace App\Services\Pricing\Sources;

class LiteLLmPricingSource extends AbstractPricingSource
{
    private const URL = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    private const ALLOWED_PROVIDERS = ['openai', 'anthropic', 'moonshot'];

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

        $raw = [];

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

            if (! $this->isRelevantModel($model, $provider)) {
                continue;
            }

            $raw[] = [
                'provider_id' => self::PROVIDER_MAP[$provider],
                'model' => $model,
                'input_per_1m' => (float) $input * 1_000_000,
                'output_per_1m' => (float) $output * 1_000_000,
                'cached_input_per_1m' => $this->extractCachedInput($entry),
            ];
        }

        // Group by (provider + price + base model). Keep shortest model name as canonical,
        // merge longer variants as aliases. Only merge when one name is a prefix of the other
        // (e.g. gpt-5.2 + gpt-5.2-chat + gpt-5.2-codex), NOT different models with same price
        // (e.g. claude-sonnet-4-5 vs claude-sonnet-4-6).
        $groups = [];
        foreach ($raw as $r) {
            $base = $this->baseModelName($r['model']);
            $priceKey = sprintf(
                '%s|%s|%.10f|%.10f|%.10f',
                $r['provider_id'],
                $base,
                $r['input_per_1m'],
                $r['output_per_1m'],
                $r['cached_input_per_1m'] ?? 0
            );

            if (! isset($groups[$priceKey])) {
                $groups[$priceKey] = [
                    'canonical' => $r['model'],
                    'provider_id' => $r['provider_id'],
                    'input_per_1m' => $r['input_per_1m'],
                    'output_per_1m' => $r['output_per_1m'],
                    'cached_input_per_1m' => $r['cached_input_per_1m'],
                    'variants' => [],
                ];
            } else {
                // Shorter name wins as canonical
                if (strlen($r['model']) < strlen($groups[$priceKey]['canonical'])) {
                    $groups[$priceKey]['variants'][] = $groups[$priceKey]['canonical'];
                    $groups[$priceKey]['canonical'] = $r['model'];
                } else {
                    $groups[$priceKey]['variants'][] = $r['model'];
                }
            }
        }

        $prices = [];
        foreach ($groups as $group) {
            $model = $group['canonical'];
            $aliases = array_values(array_unique(array_merge(
                $group['variants'],
                $this->buildAliases($model, $group['provider_id'])
            )));

            $prices[$model] = [
                'provider_id' => $group['provider_id'],
                'model' => $model,
                'input_per_1m' => $group['input_per_1m'],
                'output_per_1m' => $group['output_per_1m'],
                'cached_input_per_1m' => $group['cached_input_per_1m'],
                'currency' => 'USD',
                'aliases_json' => $aliases,
            ];
        }

        return $prices;
    }

    private function isRelevantModel(string $model, string $provider): bool
    {
        if ($provider === 'openai') {
            return (bool) preg_match('/^gpt-5(\.|$)/', $model);
        }

        if ($provider === 'anthropic') {
            return (bool) preg_match('/^claude-(sonnet|opus|haiku)-4-/', $model);
        }

        if ($provider === 'moonshot') {
            return (bool) preg_match('/^kimi-k2/', $model);
        }

        return false;
    }

    /**
     * Strip variant suffixes to find the base model for grouping.
     * e.g. gpt-5.2-codex → gpt-5.2, claude-sonnet-4-6-20260205 → claude-sonnet-4-6
     */
    private function baseModelName(string $model): string
    {
        // Strip variant suffixes that don't change the core model identity
        $base = preg_replace('/-(chat|codex|codex-max|max)$/', '', $model);
        return $base ?? $model;
    }

    private function normalizeModelName(string $key, string $provider): string
    {
        if ($provider === 'moonshot' && str_starts_with($key, 'moonshot/')) {
            $key = substr($key, strlen('moonshot/'));
        }

        $key = preg_replace('/-(\d{8}|\d{4}-\d{2}-\d{2})$/', '', $key);
        $key = preg_replace('/-latest$/', '', $key);

        return $key;
    }

    private function extractCachedInput(array $entry): ?float
    {
        $cached = $entry['cache_read_input_token_cost'] ?? $entry['cached_input_cost_per_token'] ?? null;

        return $cached !== null ? (float) $cached * 1_000_000 : null;
    }

    private function buildAliases(string $model, string $providerId): array
    {
        $aliases = [];

        if ($providerId === 'openai') {
            if (preg_match('/^gpt-(\d+\.\d+)$/', $model, $m)) {
                $aliases[] = "gpt-{$m[1]}-codex";
                $aliases[] = "gpt-{$m[1]}-codex-max";
            }
            if (preg_match('/^gpt-(\d+\.\d+)-mini$/', $model, $m)) {
                $aliases[] = "gpt-{$m[1]}-codex-mini";
                $aliases[] = "gpt-{$m[1]}-mini-codex";
            }
            if (preg_match('/^gpt-5$/', $model)) {
                $aliases[] = 'gpt-5-codex';
                $aliases[] = 'codex';
            }
        }

        if ($providerId === 'kimi') {
            if ($model === 'kimi-k2.6') {
                $aliases[] = 'kimi-for-coding';
                $aliases[] = 'kimi-code/kimi-for-coding';
                $aliases[] = 'Kimi-k2.6';
                $aliases[] = 'kimi-k2.6:cloud';
            }
        }

        if ($providerId === 'anthropic') {
            if (preg_match('/^(claude-[a-z]+-\d+-\d+)-\d{8}$/', $model, $m)) {
                $aliases[] = $m[1];
            }
            if (preg_match('/^(claude-[a-z]+)-(\d+)-(\d+)$/', $model, $m)) {
                $aliases[] = "{$m[1]}-{$m[2]}.{$m[3]}";
            }
        }

        return $aliases;
    }
}
