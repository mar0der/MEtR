<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\ModelPrice;
use App\Models\Provider;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepriceUsageTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $model, ?float $cost, int $unknown = 0): UsageEvent
    {
        static $device = null;
        $device ??= Device::factory()->create(['user_id' => User::factory()->create()->id]);

        return UsageEvent::factory()->create([
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'provider_id' => 'openai',
            'model' => $model,
            'timestamp' => '2026-10-01 10:00:00',
            'input_tokens' => 1_000_000,
            'output_tokens' => 0,
            'unknown_tokens' => $unknown,
            'official_api_cost_usd' => $cost,
            'pricing_match_confidence' => $cost === null ? 'missing' : 'exact',
        ]);
    }

    private function price(string $model, float $input): ModelPrice
    {
        return ModelPrice::create([
            'provider_id' => 'openai',
            'model' => $model,
            'input_per_1m' => $input,
            'output_per_1m' => $input * 4,
            'effective_from' => Carbon::parse('2025-01-01'),
        ]);
    }

    public function test_reprices_missing_and_changed_models_only(): void
    {
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
        $unpriced = $this->event('gpt-new', null);
        $totalOnly = $this->event('gpt-new', null, unknown: 500);
        $this->price('gpt-old', 2.0);
        $stable = $this->event('gpt-old', 2.0);

        // A dry run changes nothing.
        $this->price('gpt-new', 1.0);
        $this->artisan('metr:prices:reprice', ['--only-missing' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertNull($unpriced->fresh()->official_api_cost_usd);

        $this->artisan('metr:prices:reprice', ['--only-missing' => true])->assertSuccessful();
        $this->assertEqualsWithDelta(1.0, (float) $unpriced->fresh()->official_api_cost_usd, 1e-9);
        // Only a total token count: still cannot be priced.
        $this->assertNull($totalOnly->fresh()->official_api_cost_usd);
        $stableUpdatedAt = $stable->fresh()->updated_at;

        // gpt-old gets a new price row; --changed-since picks it up.
        $this->travel(2)->seconds();
        $since = now()->toDateTimeString();
        ModelPrice::where('model', 'gpt-old')->update(['effective_to' => Carbon::parse('2026-01-01')]);
        $newer = $this->price('gpt-old', 3.0);
        $newer->update(['effective_from' => Carbon::parse('2026-01-01')]);
        $this->artisan('metr:prices:reprice', ['--changed-since' => $since])->assertSuccessful();
        $this->assertEqualsWithDelta(3.0, (float) $stable->fresh()->official_api_cost_usd, 1e-9);
        $repricedAt = $stable->fresh()->updated_at;
        $this->assertNotEquals($stableUpdatedAt, $repricedAt);

        // Running again writes nothing (unchanged rows are not touched).
        $this->travel(2)->seconds();
        $this->artisan('metr:prices:reprice')->assertSuccessful();
        $this->assertEquals($repricedAt, $stable->fresh()->updated_at);
    }
}
