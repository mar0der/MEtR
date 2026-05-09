<?php

namespace App\Services\Pricing\Sources;

use App\Models\PriceObservation;
use GuzzleHttp\Client;

abstract class AbstractPricingSource
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    abstract public function getProviderId(): string;

    abstract public function getSourceUrl(): string;

    /**
     * @return array<string, array{input_per_1m: float|null, output_per_1m: float|null, ...}>
     */
    abstract public function fetch(): array;

    public function observe(): PriceObservation
    {
        $url = $this->getSourceUrl();
        $providerId = $this->getProviderId();

        try {
            $response = $this->http->get($url);
            $body = (string) $response->getBody();
            $hash = hash('sha256', $body);

            $parsed = $this->fetch();

            return PriceObservation::create([
                'provider_id' => $providerId,
                'source_url' => $url,
                'fetched_at' => now(),
                'source_hash' => $hash,
                'parsed_json' => $parsed,
                'status' => empty($parsed) ? 'parse_failed' : 'parsed',
                'error' => empty($parsed) ? 'No prices parsed from source' : null,
            ]);
        } catch (\Throwable $e) {
            return PriceObservation::create([
                'provider_id' => $providerId,
                'source_url' => $url,
                'fetched_at' => now(),
                'source_hash' => '',
                'parsed_json' => null,
                'status' => 'parse_failed',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
