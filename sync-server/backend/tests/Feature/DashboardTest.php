<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Project;
use App\Models\Provider;
use App\Models\ProviderAccount;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_zero_cost_and_breakdowns(): void
    {
        $user = User::factory()->create();
        $provider = Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
        $device = Device::factory()->create([
            'user_id' => $user->id,
            'display_name' => 'Petar MacBook',
            'platform' => 'macos',
        ]);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'canonical_name' => 'MEtR',
            'slug' => 'metr',
        ]);
        $account = ProviderAccount::factory()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'label' => 'OpenAI Personal',
        ]);

        UsageEvent::factory()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'provider_id' => $provider->id,
            'provider_account_id' => $account->id,
            'project_id' => $project->id,
            'model' => 'gpt-5.3-codex',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'official_api_cost_usd' => 0,
            'pricing_match_confidence' => 'exact',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('$0.00')
            ->assertSee('By Device')
            ->assertSee('Petar MacBook')
            ->assertSee('By Project')
            ->assertSee('MEtR')
            ->assertSee('By Provider Account')
            ->assertSee('OpenAI Personal')
            ->assertSee('By Model')
            ->assertSee('openai / gpt-5.3-codex');
    }
}
