<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Project;
use App\Models\Provider;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_today_preset_filters_and_shows_token_chart(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $user = User::factory()->create();
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
        Provider::factory()->create(['id' => 'anthropic', 'display_name' => 'Claude']);
        $device = Device::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'canonical_name' => 'MEtR',
            'slug' => 'metr',
        ]);

        UsageEvent::factory()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'provider_id' => 'openai',
            'project_id' => $project->id,
            'timestamp' => '2026-05-15 09:00:00',
            'model' => 'gpt-5.3-codex',
            'input_tokens' => 1000,
            'cached_input_tokens' => 300,
            'cache_read_tokens' => 50,
            'output_tokens' => 200,
            'official_api_cost_usd' => 1.50,
        ]);
        UsageEvent::factory()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'provider_id' => 'openai',
            'project_id' => $project->id,
            'timestamp' => '2026-05-14 09:00:00',
            'model' => 'gpt-5.3-codex',
            'input_tokens' => 999,
            'output_tokens' => 999,
            'official_api_cost_usd' => 9.99,
        ]);

        $this->actingAs($user)
            ->get('/reports?preset=today&metric=tokens&provider_id=openai')
            ->assertOk()
            ->assertSee('Reports')
            ->assertSee('Daily Token Report')
            ->assertSee('May 15')
            ->assertDontSee('May 14')
            ->assertSee('1,250')
            ->assertSee('$1.50')
            ->assertSee('Cached')
            ->assertSee('Input')
            ->assertSee('Output');

        Carbon::setTestNow();
    }

    public function test_reports_cost_mode_uses_same_filter_controls(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $user = User::factory()->create();
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
        $device = Device::factory()->create(['user_id' => $user->id]);

        UsageEvent::factory()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'provider_id' => 'openai',
            'timestamp' => '2026-05-15 09:00:00',
            'model' => 'gpt-5.3-codex',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'official_api_cost_usd' => 0.25,
        ]);

        $this->actingAs($user)
            ->get('/reports?preset=this_week&metric=cost&model=gpt-5.3-codex')
            ->assertOk()
            ->assertSee('Daily Cost Report')
            ->assertSee('All providers')
            ->assertSee('gpt-5.3-codex')
            ->assertSee('$0.25');

        Carbon::setTestNow();
    }
}
