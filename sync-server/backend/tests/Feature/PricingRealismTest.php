<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\ModelPrice;
use App\Models\PriceObservation;
use App\Models\Provider;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Pricing\Sources\LiteLLmPricingSource;
use App\Services\Pricing\UpdateProviderPrices;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PricingRealismTest extends TestCase
{
    use RefreshDatabase;

    /** LiteLLM rates whose per-million value is not exact in floats (3e-06 * 1e6). */
    private const CATALOG = [
        'claude-opus-5-5' => [
            'litellm_provider' => 'anthropic',
            'input_cost_per_token' => 3e-06,
            'output_cost_per_token' => 1.5e-05,
            'cache_creation_input_token_cost' => 3.75e-06,
            'cache_read_input_token_cost' => 3e-07,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Provider::factory()->create(['id' => 'anthropic', 'display_name' => 'Anthropic']);
    }

    /** Serves the catalog to $runs update runs (observe() fetches twice per run). */
    private function fakeLiteLlm(int $runs): void
    {
        Provider::factory()->create(['id' => 'litellm', 'display_name' => 'LiteLLM']);
        $body = json_encode(self::CATALOG);
        $responses = array_fill(0, $runs * 2, new Response(200, [], $body));
        $source = new LiteLLmPricingSource;
        (new \ReflectionProperty($source, 'http'))->setValue($source, new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
        $this->app->instance(LiteLLmPricingSource::class, $source);
    }

    private function device(string $name = 'old-windows'): Device
    {
        return Device::factory()->create([
            'user_id' => User::factory()->create()->id,
            'display_name' => $name,
            'last_seen_at' => '2026-10-09 11:00:00',
        ]);
    }

    private function claudeEvent(Device $device, array $overrides = []): UsageEvent
    {
        return UsageEvent::factory()->create(array_merge([
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'provider_id' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'timestamp' => '2026-10-01 10:00:00',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_write_tokens' => 1_000_000,
            'cache_write_1h_tokens' => 0,
            // Stored before the server kept the 1-hour split.
            'created_at' => '2026-10-01 10:05:00',
        ], $overrides));
    }

    private function price(array $overrides = []): ModelPrice
    {
        return ModelPrice::create(array_merge([
            'provider_id' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'input_per_1m' => 5.0,
            'output_per_1m' => 25.0,
            'cache_write_per_1m' => 6.25,
            'cache_read_per_1m' => 0.5,
            'effective_from' => Carbon::parse('2025-01-01'),
        ], $overrides));
    }

    public function test_a_second_update_adds_no_rows_and_reprices_nothing(): void
    {
        $this->fakeLiteLlm(2);
        $event = $this->claudeEvent($this->device(), ['cache_write_tokens' => 0, 'input_tokens' => 1_000_000]);

        $this->artisan('metr:prices:update')->assertSuccessful();
        $rows = ModelPrice::count();
        $this->assertSame(1, $rows);
        $this->assertSame('3.0000000000', ModelPrice::first()->input_per_1m);
        $this->assertEqualsWithDelta(3.0, (float) $event->fresh()->official_api_cost_usd, 1e-9);

        $this->travel(1)->days();
        $this->artisan('metr:prices:update')
            ->expectsOutputToContain('Nothing to reprice.')
            ->assertSuccessful();
        $this->assertSame($rows, ModelPrice::count());
    }

    public function test_update_keeps_one_open_row_per_model(): void
    {
        $this->fakeLiteLlm(1);
        $rates = ['input_per_1m' => 3, 'output_per_1m' => 15, 'cache_write_per_1m' => 3.75, 'cache_read_per_1m' => 0.3, 'cached_input_per_1m' => 0.3];
        $old = $this->price($rates);
        $this->travel(1)->days();
        $middle = $this->price($rates);
        $this->travel(1)->days();
        $newest = $this->price($rates);

        $this->artisan('metr:prices:update')->assertSuccessful();

        $this->assertSame(3, ModelPrice::count());
        $this->assertSame([$newest->id], ModelPrice::whereNull('effective_to')->pluck('id')->all());
        $this->assertNotNull($old->fresh()->effective_to);
        $this->assertNotNull($middle->fresh()->effective_to);
    }

    public function test_prices_equal_tolerates_float_noise(): void
    {
        $stored = $this->price(['input_per_1m' => 3.0, 'output_per_1m' => 15.0, 'cache_write_per_1m' => null, 'cache_read_per_1m' => null]);
        $rates = array_fill_keys(UpdateProviderPrices::RATE_FIELDS, null);

        $this->assertTrue(UpdateProviderPrices::pricesEqual($stored->fresh(), ['input_per_1m' => 3e-06 * 1_000_000, 'output_per_1m' => 15.0] + $rates));
        $this->assertFalse(UpdateProviderPrices::pricesEqual($stored->fresh(), ['input_per_1m' => 3.01, 'output_per_1m' => 15.0] + $rates));
        $this->assertFalse(UpdateProviderPrices::pricesEqual($stored->fresh(), ['input_per_1m' => 3.0, 'output_per_1m' => 15.0, 'cache_read_per_1m' => 0.0] + $rates));
    }

    public function test_a_second_price_job_is_refused_while_one_runs(): void
    {
        $lock = Cache::lock('metr:prices', 60);
        $this->assertTrue($lock->get());

        $this->artisan('metr:prices:reprice')
            ->expectsOutputToContain('Another price job')
            ->assertFailed();
        $this->artisan('metr:prices:update')
            ->expectsOutputToContain('Another price job')
            ->assertFailed();
        // A dry run writes nothing and is allowed.
        $this->artisan('metr:prices:reprice', ['--dry-run' => true])->assertSuccessful();

        $lock->release();
        $this->artisan('metr:prices:reprice')->assertSuccessful();
    }

    public function test_estimate_prices_old_claude_cache_writes_as_mostly_one_hour_and_can_be_undone(): void
    {
        $price = $this->price();
        $old = $this->device('old-windows');
        $current = $this->device('current-mac');
        $legacy = $this->claudeEvent($old, ['official_api_cost_usd' => 6.25, 'pricing_match_confidence' => 'exact']);
        $reported = $this->claudeEvent($old, ['cache_write_1h_tokens' => 1_000_000, 'official_api_cost_usd' => 10.0, 'pricing_match_confidence' => 'exact']);
        $newer = $this->claudeEvent($old, ['created_at' => '2026-10-30 08:00:00', 'official_api_cost_usd' => 6.25, 'pricing_match_confidence' => 'exact']);
        $otherDevice = $this->claudeEvent($current, ['official_api_cost_usd' => 6.25, 'pricing_match_confidence' => 'exact']);

        $this->artisan('metr:prices:estimate-1h-cache')->assertFailed();
        // Ids only: display names are not unique across users.
        $this->artisan('metr:prices:estimate-1h-cache', ['--device' => ['old-windows']])->assertFailed();
        // A device that synced since the cutoff should resend instead.
        $current->update(['last_seen_at' => '2026-10-30 08:00:00']);
        $this->artisan('metr:prices:estimate-1h-cache', ['--device' => [$current->id]])->assertFailed();

        $this->artisan('metr:prices:estimate-1h-cache', ['--device' => [$old->id], '--dry-run' => true])
            ->expectsOutputToContain('+3.38')
            ->assertSuccessful();
        $this->assertSame('exact', $legacy->fresh()->pricing_match_confidence);

        $this->artisan('metr:prices:estimate-1h-cache', ['--device' => [$old->id]])->assertSuccessful();
        // 900k x 10 + 100k x 6.25; raw token columns untouched.
        $this->assertEqualsWithDelta(9.625, (float) $legacy->fresh()->official_api_cost_usd, 1e-9);
        $this->assertSame('estimated', $legacy->fresh()->pricing_match_confidence);
        $this->assertTrue($legacy->fresh()->hasUnreportedOneHourSplit());
        $this->assertSame(0, $legacy->fresh()->cache_write_1h_tokens);
        $this->assertEqualsWithDelta(10.0, (float) $reported->fresh()->official_api_cost_usd, 1e-9);
        // Stored after the server kept the split: a reported 0 is real.
        $this->assertEqualsWithDelta(6.25, (float) $newer->fresh()->official_api_cost_usd, 1e-9);
        $this->assertEqualsWithDelta(6.25, (float) $otherDevice->fresh()->official_api_cost_usd, 1e-9);

        // A full reprice keeps the estimate, even across a time without a price.
        $this->artisan('metr:prices:reprice')->assertSuccessful();
        $this->assertEqualsWithDelta(9.625, (float) $legacy->fresh()->official_api_cost_usd, 1e-9);
        $price->update(['model' => 'claude-renamed']);
        $this->artisan('metr:prices:reprice')->assertSuccessful();
        $this->assertNull($legacy->fresh()->official_api_cost_usd);
        $price->update(['model' => 'claude-opus-5-5']);
        $this->artisan('metr:prices:reprice')->assertSuccessful();
        $this->assertSame('estimated', $legacy->fresh()->pricing_match_confidence);
        $this->assertEqualsWithDelta(9.625, (float) $legacy->fresh()->official_api_cost_usd, 1e-9);

        $this->artisan('metr:prices:estimate-1h-cache', ['--device' => [$old->id], '--undo' => true])->assertSuccessful();
        $this->assertSame('exact', $legacy->fresh()->pricing_match_confidence);
        $this->assertFalse($legacy->fresh()->hasUnreportedOneHourSplit());
        $this->assertEqualsWithDelta(6.25, (float) $legacy->fresh()->official_api_cost_usd, 1e-9);
    }

    public function test_resent_events_keep_the_estimate_until_a_split_is_reported(): void
    {
        $this->price();
        $user = User::factory()->create();
        Device::factory()->create(['user_id' => $user->id, 'device_uuid' => 'd1']);
        $tokens = ['input' => 0, 'output' => 0, 'cached_input' => 0, 'cache_write' => 1_000_000,
            'cache_read' => 0, 'reasoning' => 0, 'tool' => 0, 'unknown' => 0];
        $upload = fn (array $tokens, array $warnings = []) => $this->actingAs($user)->postJson('/api/v1/sync/events', [
            'device_uuid' => 'd1',
            'client_batch_id' => 'b-'.uniqid(),
            'events' => [[
                'source_event_id' => 'evt-1',
                'source_event_hash' => 'hash-1',
                'request_id' => 'req-1',
                'provider_id' => 'anthropic',
                'timestamp' => '2026-10-01T10:00:00Z',
                'model' => 'claude-opus-5-5',
                'tokens' => $tokens,
                'warnings' => $warnings,
            ]],
        ])->assertOk();

        // Old client: no split reported.
        $upload($tokens, ['client-warning']);
        $event = UsageEvent::first();
        $this->assertTrue($event->hasUnreportedOneHourSplit());
        $this->assertSame(['client-warning', UsageEvent::ONE_HOUR_SPLIT_UNREPORTED], json_decode($event->warnings_json, true));
        $this->assertSame('{"a":"x"}', UsageEvent::warningsWithUnreportedSplit('{"a":"x"}', false));
        $this->assertEqualsWithDelta(9.625, (float) $event->official_api_cost_usd, 1e-9);

        // Upgraded client whose old rows were never re-parsed: 0 keeps the estimate.
        $upload($tokens + ['cache_write_1h' => 0]);
        $this->assertSame('estimated', UsageEvent::first()->pricing_match_confidence);
        $this->assertEqualsWithDelta(9.625, (float) UsageEvent::first()->official_api_cost_usd, 1e-9);

        // A real split replaces the estimate.
        $upload($tokens + ['cache_write_1h' => 400_000]);
        $event = UsageEvent::first();
        $this->assertFalse($event->hasUnreportedOneHourSplit());
        $this->assertNull($event->warnings_json);
        $this->assertSame('exact', $event->pricing_match_confidence);
        $this->assertEqualsWithDelta(3.75 + 4.00, (float) $event->official_api_cost_usd, 1e-9);

        // A new event reported with 0 by a current client is a real 5-minute write.
        $this->actingAs($user)->postJson('/api/v1/sync/events', [
            'device_uuid' => 'd1',
            'client_batch_id' => 'b-new',
            'events' => [[
                'source_event_id' => 'evt-2', 'source_event_hash' => 'hash-2', 'request_id' => 'req-2',
                'provider_id' => 'anthropic', 'timestamp' => '2026-10-30T10:00:00Z', 'model' => 'claude-opus-5-5',
                'tokens' => $tokens + ['cache_write_1h' => 0], 'warnings' => [],
            ]],
        ])->assertOk();
        $this->assertEqualsWithDelta(6.25, (float) UsageEvent::where('request_id', 'req-2')->first()->official_api_cost_usd, 1e-9);
    }

    public function test_update_ignores_open_rows_of_a_model_spelled_differently(): void
    {
        $this->fakeLiteLlm(1);
        $other = $this->price(['model' => 'Claude-Opus-5-5']);

        $this->artisan('metr:prices:update')->assertSuccessful();

        $this->assertNull($other->fresh()->effective_to);
        $this->assertSame(1, ModelPrice::where('model', 'claude-opus-5-5')->whereNull('effective_to')->count());
    }

    public function test_prune_deletes_only_unused_older_copies_of_the_current_row(): void
    {
        $closed = ['effective_to' => Carbon::parse('2026-10-20')];
        $unused = $this->price($closed);
        $used = $this->price($closed);
        $different = $this->price($closed + ['input_per_1m' => 4.0]);
        $otherAliases = $this->price($closed + ['aliases_json' => json_encode(['opus-x'])]);
        $this->travel(1)->minutes();
        $current = $this->price();
        $this->travel(1)->minutes();
        $newerClosed = $this->price($closed);
        $this->claudeEvent($this->device(), ['model_price_id' => $used->id]);

        $this->artisan('metr:prices:prune-duplicates', ['--dry-run' => true])
            ->expectsOutputToContain('Would delete 1 duplicate price rows')
            ->assertSuccessful();
        $this->assertSame(6, ModelPrice::count());

        $this->artisan('metr:prices:prune-duplicates')->assertSuccessful();
        $this->assertNull(ModelPrice::find($unused->id));
        foreach ([$used, $different, $otherAliases, $current, $newerClosed] as $kept) {
            $this->assertNotNull(ModelPrice::find($kept->id));
        }
    }
}
