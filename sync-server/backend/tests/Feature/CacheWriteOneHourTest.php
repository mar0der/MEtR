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

class CacheWriteOneHourTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_hour_cache_writes_are_stored_and_priced_at_twice_input(): void
    {
        Provider::factory()->create(['id' => 'anthropic', 'display_name' => 'Anthropic']);
        ModelPrice::create([
            'provider_id' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'input_per_1m' => 5.00,
            'output_per_1m' => 25.00,
            'cache_write_per_1m' => 6.25,
            'cache_read_per_1m' => 0.50,
            'effective_from' => Carbon::parse('2025-01-01'),
        ]);
        $user = User::factory()->create();
        Device::factory()->create(['user_id' => $user->id, 'device_uuid' => 'd1']);

        $tokens = ['input' => 0, 'output' => 0, 'cached_input' => 0, 'cache_write' => 1_000_000,
            'cache_read' => 0, 'reasoning' => 0, 'tool' => 0, 'unknown' => 0];
        $upload = fn (array $tokens) => $this->actingAs($user)->postJson('/api/v1/sync/events', [
            'device_uuid' => 'd1',
            'client_batch_id' => 'b-'.uniqid(),
            'events' => [[
                'source_event_id' => 'evt-1',
                'source_event_hash' => 'hash-1',
                'request_id' => 'req-1',
                'provider_id' => 'anthropic',
                'timestamp' => '2026-10-29T10:00:00Z',
                'model' => 'claude-opus-5-5',
                'tokens' => $tokens,
                'warnings' => [],
            ]],
        ])->assertOk();

        // An older client: no 1-hour split, all writes at the 5-minute rate.
        $upload($tokens);
        $this->assertEqualsWithDelta(6.25, (float) UsageEvent::first()->official_api_cost_usd, 1e-6);

        // Re-sent with 400k of the writes at 1-hour TTL: 600k x 6.25 + 400k x 10.
        $upload($tokens + ['cache_write_1h' => 400_000]);
        $event = UsageEvent::first();
        $this->assertSame(1, UsageEvent::count());
        $this->assertSame(400_000, $event->cache_write_1h_tokens);
        $this->assertEqualsWithDelta(3.75 + 4.00, (float) $event->official_api_cost_usd, 1e-6);
    }

    public function test_uploads_work_before_the_migration_adds_the_column(): void
    {
        \Illuminate\Support\Facades\Schema::table('usage_events', fn ($table) => $table->dropColumn('cache_write_1h_tokens'));
        $flag = new \ReflectionProperty(\App\Services\Sync\IngestUsageEvents::class, 'oneHourColumn');
        $flag->setValue(null, null);
        try {
            Provider::factory()->create(['id' => 'anthropic', 'display_name' => 'Anthropic']);
            $user = User::factory()->create();
            Device::factory()->create(['user_id' => $user->id, 'device_uuid' => 'd1']);
            $this->actingAs($user)->postJson('/api/v1/sync/events', [
                'device_uuid' => 'd1',
                'client_batch_id' => 'b-1',
                'events' => [[
                    'source_event_id' => 'evt-1',
                    'source_event_hash' => 'hash-1',
                    'provider_id' => 'anthropic',
                    'timestamp' => '2026-10-29T10:00:00Z',
                    'model' => 'claude-opus-5-5',
                    'tokens' => ['input' => 1, 'output' => 1, 'cached_input' => 0, 'cache_write' => 10,
                        'cache_write_1h' => 5, 'cache_read' => 0, 'reasoning' => 0, 'tool' => 0, 'unknown' => 0],
                    'warnings' => [],
                ]],
            ])->assertOk();
            $this->assertSame(1, UsageEvent::count());
            // Without the stored split, the cost ignores it too (stays consistent on repricing).
            $this->assertSame(10, (int) UsageEvent::first()->cache_write_tokens);
        } finally {
            $flag->setValue(null, null);
        }
    }
}
