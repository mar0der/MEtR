<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-29 12:00:00');
        Provider::factory()->create(['id' => 'anthropic', 'display_name' => 'Claude']);
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
        Provider::factory()->create(['id' => 'cursor', 'display_name' => 'Cursor']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_older_autorenew_cycle_does_not_block_later_catch_up(): void
    {
        $user = User::factory()->create();

        $this->cycle($user, 'anthropic', 'Petar', '2026-06-06', '2026-07-05', 21, 6);
        $this->cycle($user, 'anthropic', 'Petar', '2026-07-06', '2026-08-05', 21, 6);

        $this->artisan('metr:subscriptions:renew')
            ->expectsOutput('Renewed 1 subscription instance(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'provider_id' => 'anthropic',
            'plan_name' => 'Petar',
            'started_on' => '2026-08-06',
            'ended_on' => '2026-09-05',
            'monthly_price' => 21,
        ]);
        $this->assertSame(3, Subscription::count());
    }

    public function test_one_overlapping_group_does_not_abort_other_accounts(): void
    {
        $user = User::factory()->create();

        $this->cycle($user, 'anthropic', 'Petar', '2026-06-06', '2026-07-05', 21, 6);
        $this->cycle($user, 'anthropic', 'Petar', '2026-07-06', '2026-08-05', 21, 6);
        $this->cycle($user, 'openai', 'Doka', '2026-06-01', '2026-06-30', 40, 1);

        $this->artisan('metr:subscriptions:renew')->assertSuccessful();

        $this->assertDatabaseHas('subscriptions', [
            'provider_id' => 'anthropic',
            'plan_name' => 'Petar',
            'started_on' => '2026-08-06',
            'ended_on' => '2026-09-05',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'provider_id' => 'openai',
            'plan_name' => 'Doka',
            'started_on' => '2026-07-01',
            'ended_on' => '2026-07-31',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'provider_id' => 'openai',
            'plan_name' => 'Doka',
            'started_on' => '2026-08-01',
            'ended_on' => '2026-08-31',
        ]);
    }

    public function test_uses_renewal_price_for_new_cycles(): void
    {
        $user = User::factory()->create();

        Subscription::create([
            'user_id' => $user->id,
            'provider_id' => 'openai',
            'plan_name' => 'Petar B',
            'monthly_price' => 10.889040,
            'renewal_price' => 21.783526,
            'currency' => 'USD',
            'billing_anchor_day' => 17,
            'started_on' => '2026-06-17',
            'ended_on' => '2026-07-16',
            'active' => true,
            'autorenew' => true,
        ]);

        $this->artisan('metr:subscriptions:renew')->assertSuccessful();

        $created = Subscription::where('plan_name', 'Petar B')
            ->whereDate('started_on', '2026-07-17')
            ->first();

        $this->assertNotNull($created);
        $this->assertEqualsWithDelta(21.783526, (float) $created->monthly_price, 0.000001);
        $this->assertSame('2026-08-16', $created->ended_on->toDateString());
    }

    public function test_cursor_catch_up_from_june_25_billing_anchor(): void
    {
        $user = User::factory()->create();

        $this->cycle($user, 'cursor', 'Petar', '2026-06-25', '2026-07-24', 20, 25);

        $this->artisan('metr:subscriptions:renew')
            ->expectsOutput('Renewed 2 subscription instance(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('subscriptions', [
            'provider_id' => 'cursor',
            'started_on' => '2026-07-25',
            'ended_on' => '2026-08-24',
            'monthly_price' => 20,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'provider_id' => 'cursor',
            'started_on' => '2026-08-25',
            'ended_on' => '2026-09-24',
            'monthly_price' => 20,
        ]);
    }

    public function test_does_not_create_when_latest_cycle_still_covers_today(): void
    {
        $user = User::factory()->create();
        $this->cycle($user, 'anthropic', 'Petar', '2026-08-06', '2026-09-05', 21, 6);

        $this->artisan('metr:subscriptions:renew')
            ->expectsOutput('Renewed 0 subscription instance(s).')
            ->assertSuccessful();

        $this->assertSame(1, Subscription::count());
    }

    private function cycle(
        User $user,
        string $providerId,
        string $planName,
        string $startedOn,
        string $endedOn,
        float $price,
        int $anchor,
    ): Subscription {
        return Subscription::create([
            'user_id' => $user->id,
            'provider_id' => $providerId,
            'plan_name' => $planName,
            'monthly_price' => $price,
            'renewal_price' => $price,
            'currency' => 'USD',
            'billing_anchor_day' => $anchor,
            'started_on' => $startedOn,
            'ended_on' => $endedOn,
            'active' => true,
            'autorenew' => true,
        ]);
    }
}
