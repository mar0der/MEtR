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
            ->get('/dashboard?tab=all')
            ->assertOk()
            ->assertSee('$0.00')
            ->assertSee('By Device')
            ->assertSee('Petar MacBook')
            ->assertSee('By Project')
            ->assertSee('MEtR')
            ->assertSee('By Provider Account')
            ->assertSee('OpenAI Personal')
            ->assertSee('By Model')
            ->assertSee('openai / gpt-5.3-codex')
            ->assertSee('Avg Cache')
            ->assertSee('Avg Input')
            ->assertSee('Avg Output')
            ->assertSee('Avg Cost/Event');
    }

    public function test_dashboard_provider_filter_limits_events_and_summaries(): void
    {
        $user = User::factory()->create();
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
        Provider::factory()->create(['id' => 'anthropic', 'display_name' => 'Claude']);
        $device = Device::factory()->create(['user_id' => $user->id]);

        $openaiProject = Project::factory()->create([
            'user_id' => $user->id,
            'canonical_name' => 'OpenAI Project',
            'slug' => 'openai-project',
        ]);
        $claudeProject = Project::factory()->create([
            'user_id' => $user->id,
            'canonical_name' => 'Claude Project',
            'slug' => 'claude-project',
        ]);

        UsageEvent::factory()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'provider_id' => 'openai',
            'project_id' => $openaiProject->id,
            'model' => 'gpt-5.3-codex',
            'input_tokens' => 100,
            'output_tokens' => 50,
        ]);
        UsageEvent::factory()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'provider_id' => 'anthropic',
            'project_id' => $claudeProject->id,
            'model' => 'claude-sonnet-4-5',
            'input_tokens' => 200,
            'output_tokens' => 75,
        ]);

        $this->actingAs($user)
            ->get('/dashboard?tab=events&provider_id=anthropic')
            ->assertOk()
            ->assertSee('Claude Project')
            ->assertSee('claude-sonnet-4-5')
            ->assertDontSee('<td>OpenAI Project</td>', false)
            ->assertDontSee('>gpt-5.3-codex</td>', false);

        $this->actingAs($user)
            ->get('/dashboard?tab=all&provider_id=anthropic')
            ->assertOk()
            ->assertSee('Claude Project')
            ->assertSee('anthropic / claude-sonnet-4-5')
            ->assertDontSee('<td>OpenAI Project</td>', false)
            ->assertDontSee('openai / gpt-5.3-codex');
    }

    public function test_dashboard_summary_headers_sort_tables(): void
    {
        $user = User::factory()->create();
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
        $device = Device::factory()->create(['user_id' => $user->id]);

        UsageEvent::factory()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'provider_id' => 'openai',
            'model' => 'low-cost-model',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'official_api_cost_usd' => 0.01,
        ]);
        UsageEvent::factory()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'provider_id' => 'openai',
            'model' => 'high-cost-model',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'official_api_cost_usd' => 5.00,
        ]);

        $response = $this->actingAs($user)
            ->get('/dashboard?tab=accounts&model_sort=cost&model_dir=desc')
            ->assertOk()
            ->assertSee('model_sort=cost')
            ->assertSee('model_dir=asc')
            ->assertSee('sortable-header active');

        $modelTable = strstr($response->getContent(), 'By Model');

        $this->assertNotFalse($modelTable);
        $this->assertLessThan(
            strpos($modelTable, 'openai / low-cost-model'),
            strpos($modelTable, 'openai / high-cost-model')
        );
    }
}
