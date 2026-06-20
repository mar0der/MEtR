<?php

namespace Tests\Unit;

use App\Models\Provider;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\CalculateSubscriptionCost;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculateSubscriptionCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_full_month_subscription_cost(): void
    {
        $user = User::factory()->create();
        Provider::factory()->create(['id' => 'anthropic']);

        Subscription::create([
            'user_id' => $user->id,
            'provider_id' => 'anthropic',
            'source_subscription_id' => 'sub-1',
            'plan_name' => 'Pro',
            'monthly_price' => 20.00,
            'started_on' => Carbon::parse('2026-05-01'),
            'ended_on' => Carbon::parse('2026-05-31'),
            'active' => true,
        ]);

        $result = app(CalculateSubscriptionCost::class)->forPeriod(
            $user,
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31')
        );

        $this->assertEqualsWithDelta(20.00, $result['total'], 0.001);
        $this->assertEqualsWithDelta(20.00, $result['by_provider']['anthropic'], 0.001);
        $this->assertEqualsWithDelta(20.00 / 31, $result['daily_total']['2026-05-15'], 0.001);
    }

    public function test_prorated_partial_month_subscription(): void
    {
        $user = User::factory()->create();
        Provider::factory()->create(['id' => 'openai']);

        Subscription::create([
            'user_id' => $user->id,
            'provider_id' => 'openai',
            'source_subscription_id' => 'sub-2',
            'plan_name' => 'Plus',
            'monthly_price' => 30.00,
            'started_on' => Carbon::parse('2026-02-01'),
            'ended_on' => Carbon::parse('2026-02-28'),
            'active' => true,
        ]);

        $result = app(CalculateSubscriptionCost::class)->forPeriod(
            $user,
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-14')
        );

        $dailyRate = 30.00 / 28;
        $this->assertEqualsWithDelta(5 * $dailyRate, $result['total'], 0.001);
    }

    public function test_multiple_overlapping_provider_subscriptions(): void
    {
        $user = User::factory()->create();
        Provider::factory()->create(['id' => 'openai']);
        Provider::factory()->create(['id' => 'anthropic']);

        Subscription::create([
            'user_id' => $user->id,
            'provider_id' => 'openai',
            'source_subscription_id' => 'sub-o1',
            'plan_name' => 'Plus',
            'monthly_price' => 30.00,
            'started_on' => Carbon::parse('2026-05-01'),
            'ended_on' => Carbon::parse('2026-05-31'),
            'active' => true,
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'provider_id' => 'anthropic',
            'source_subscription_id' => 'sub-a1',
            'plan_name' => 'Pro',
            'monthly_price' => 20.00,
            'started_on' => Carbon::parse('2026-05-01'),
            'ended_on' => Carbon::parse('2026-05-31'),
            'active' => true,
        ]);

        $result = app(CalculateSubscriptionCost::class)->forPeriod(
            $user,
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31'),
            ['openai']
        );

        $this->assertEqualsWithDelta(30.00, $result['total'], 0.001);
        $this->assertEqualsWithDelta(30.00, $result['by_provider']['openai'], 0.001);
        $this->assertArrayNotHasKey('anthropic', $result['by_provider']);
    }
}
