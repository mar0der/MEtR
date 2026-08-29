<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_price_and_renews_at_totals_exclude_inactive_accounts(): void
    {
        Carbon::setTestNow('2026-08-29 12:00:00');

        $user = User::factory()->create();
        Provider::factory()->create(['id' => 'anthropic', 'display_name' => 'Claude']);
        Provider::factory()->create(['id' => 'kimi', 'display_name' => 'Kimi']);

        Subscription::create([
            'user_id' => $user->id,
            'provider_id' => 'anthropic',
            'plan_name' => 'Petar',
            'monthly_price' => 21,
            'renewal_price' => 21,
            'currency' => 'USD',
            'billing_anchor_day' => 6,
            'started_on' => '2026-08-06',
            'ended_on' => '2026-09-05',
            'active' => true,
            'autorenew' => true,
        ]);
        Subscription::create([
            'user_id' => $user->id,
            'provider_id' => 'kimi',
            'plan_name' => 'Petar',
            'monthly_price' => 39,
            'renewal_price' => 39,
            'currency' => 'USD',
            'billing_anchor_day' => 25,
            'started_on' => '2026-05-25',
            'ended_on' => '2026-06-24',
            'active' => true,
            'autorenew' => false,
        ]);

        $response = $this->actingAs($user)->get('/subscriptions?tab=accounts');
        $response->assertOk();

        preg_match('/<tfoot>.*?<\/tfoot>/s', $response->getContent(), $footer);
        $this->assertNotEmpty($footer);
        $this->assertStringContainsString('$21.00', $footer[0]);
        $this->assertStringContainsString('$60.00', $footer[0]);
        $this->assertStringNotContainsString('$39.00', $footer[0]);

        Carbon::setTestNow();
    }
}
