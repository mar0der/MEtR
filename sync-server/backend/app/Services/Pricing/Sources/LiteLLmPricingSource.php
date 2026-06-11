<?php

namespace App\Services\Pricing\Sources;

class LiteLLmPricingSource extends AbstractPricingSource
{
    private const URL = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    /**
     * Providers we import. We keep ALL their models for reliable log matching.
     */
    private const ALLOWED_PROVIDERS = ['openai', 'anthropic', 'moonshot', 'gemini'];

    /**
     * Map LiteLLM provider → our internal provider_id.
     */
    private const PROVIDER_MAP = [
        'openai' => 'openai',
        'anthropic' => 'anthropic',
        'moonshot' => 'kimi',
        'gemini' => 'google',
    ];

    /**
     * Prefixes LiteLLM adds to model names for routed providers.
     */
    private const PREFIXES = [
        'azure/', 'azure/global/', 'azure/us/', 'azure/eu/',
        'openrouter/', 'groq/', 'together_ai/', 'replicate/',
        'bedrock/', 'vertex_ai/', 'fireworks_ai/', 'deepinfra/',
        'baseten/', 'hyperbolic/', 'novita/', 'crusoe/',
        'wandb/', 'vercel_ai_gateway/', 'gmi/', 'databricks/',
        'aiml/',
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
            $internalProvider = self::PROVIDER_MAP[$provider];

            // Skip duplicate model names after normalization
            if (isset($prices[$model])) {
                continue;
            }

            $prices[$model] = [
                'provider_id' => $internalProvider,
                'model' => $model,
                'input_per_1m' => (float) $input * 1_000_000,
                'output_per_1m' => (float) $output * 1_000_000,
                'cached_input_per_1m' => $this->extractCachedInput($entry),
                'cache_write_per_1m' => $this->extractCacheWrite($entry),
                'cache_read_per_1m' => $this->extractCacheRead($entry),
                'currency' => 'USD',
                'aliases_json' => $this->buildAliases($model, $internalProvider),
            ];
        }

        return $prices;
    }

    private function normalizeModelName(string $key, string $provider): string
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $key = substr($key, strlen($prefix));
                break;
            }
        }

        // For moonshot entries, keep the model name after moonshot/
        if ($provider === 'moonshot' && str_starts_with($key, 'moonshot/')) {
            $key = substr($key, strlen('moonshot/'));
        }

        return $key;
    }

    private function extractCachedInput(array $entry): ?float
    {
        $cached = $entry['cache_read_input_token_cost'] ?? $entry['cached_input_cost_per_token'] ?? null;

        return $cached !== null ? (float) $cached * 1_000_000 : null;
    }

    private function extractCacheWrite(array $entry): ?float
    {
        $cost = $entry['cache_creation_input_token_cost'] ?? null;

        return $cost !== null ? (float) $cost * 1_000_000 : null;
    }

    private function extractCacheRead(array $entry): ?float
    {
        $cost = $entry['cache_read_input_token_cost'] ?? null;

        return $cost !== null ? (float) $cost * 1_000_000 : null;
    }

    /**
     * Build alias list for a model to improve log matching.
     */
    private function buildAliases(string $model, string $providerId): array
    {
        $aliases = [];

        // Version-number dot aliases: claude-haiku-4-5 → claude-haiku-4.5
        $dotted = preg_replace('/(\d)-(\d)(?!\d)/', '$1.$2', $model);
        if ($dotted !== $model && ! in_array($dotted, $aliases, true)) {
            $aliases[] = $dotted;
        }

        // Provider-specific aliases
        if ($providerId === 'openai') {
            $aliases = array_merge($aliases, $this->buildOpenAIAliases($model));
        }

        if ($providerId === 'kimi') {
            $aliases = array_merge($aliases, $this->buildKimiAliases($model));
        }

        if ($providerId === 'anthropic') {
            $aliases = array_merge($aliases, $this->buildAnthropicAliases($model));
        }

        return array_values(array_unique($aliases));
    }

    private function buildOpenAIAliases(string $model): array
    {
        $aliases = [];

        // gpt-5 → codex
        if ($model === 'gpt-5' || $model === 'gpt-5-codex') {
            $aliases[] = 'codex';
        }

        // gpt-5.X base variants
        if (preg_match('/^gpt-5\.\d+$/', $model)) {
            $aliases[] = $model . '-chat';
            $aliases[] = $model . '-codex';
            $aliases[] = $model . '-codex-max';
        }

        // gpt-5.X-pro
        if (preg_match('/^gpt-5\.\d+-pro$/', $model)) {
            $base = preg_replace('/-pro$/', '', $model);
            $aliases[] = $base . '-pro';
        }

        // gpt-5.X-mini variants
        if (preg_match('/^gpt-5\.\d+-mini$/', $model)) {
            $aliases[] = str_replace('-mini', '-codex-mini', $model);
            $aliases[] = str_replace('-mini', '-mini-codex', $model);
        }

        // gpt-5.X-nano
        if (preg_match('/^gpt-5\.\d+-nano$/', $model)) {
            $aliases[] = str_replace('-nano', '', $model) . '-nano';
        }

        return $aliases;
    }

    private function buildKimiAliases(string $model): array
    {
        $aliases = [];

        // kimi-k2.6 is also known as kimi-for-coding
        if ($model === 'kimi-k2.6') {
            $aliases[] = 'kimi-for-coding';
            $aliases[] = 'kimi-code/kimi-for-coding';
            $aliases[] = 'Kimi-k2.6';
            $aliases[] = 'kimi-k2.6:cloud';
        }

        return $aliases;
    }

    private function buildAnthropicAliases(string $model): array
    {
        $aliases = [];

        // Claude model families: claude-haiku-4-5 → claude-haiku-4.5 already handled by dot alias above
        // Additional known aliases from seed data
        $known = [
            'claude-haiku-4-5' => ['claude-haiku-4.5'],
            'claude-sonnet-4-5' => ['claude-sonnet-4.5'],
            'claude-sonnet-4-6' => ['claude-sonnet-4.6'],
            'claude-opus-4-1' => ['claude-opus-4.1'],
            'claude-opus-4-5' => ['claude-opus-4.5'],
            'claude-opus-4-6' => ['claude-opus-4.6'],
            'claude-opus-4-7' => ['claude-opus-4.7'],
            'claude-fable-5' => [],
        ];

        if (isset($known[$model])) {
            foreach ($known[$model] as $alias) {
                if (! in_array($alias, $aliases, true)) {
                    $aliases[] = $alias;
                }
            }
        }

        return $aliases;
    }
}
