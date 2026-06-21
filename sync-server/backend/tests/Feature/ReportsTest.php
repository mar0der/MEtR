<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Project;
use App\Models\Provider;
use App\Models\ReportFavorite;
use App\Models\Subscription;
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

    public function test_reports_show_cost_per_million_and_effective_cost_per_million(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $user = User::factory()->create();
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
        $device = Device::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['user_id' => $user->id, 'canonical_name' => 'MEtR', 'slug' => 'metr']);

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

        Subscription::create([
            'user_id' => $user->id,
            'provider_id' => 'openai',
            'plan_name' => 'ChatGPT Plus',
            'monthly_price' => 30.00,
            'currency' => 'USD',
            'started_on' => '2026-05-15',
            'ended_on' => '2026-05-15',
            'active' => true,
            'autorenew' => false,
        ]);

        // total_tokens = (1000 - 300) + (300 + 50) + 200 = 1250
        // cost_per_million = 1.50 / 1250 * 1_000_000 = 1_200.00
        // paid_ratio = 30.00 / 1.50 = 20
        // effective_cost_per_million = 1_200.00 * 20 = 24_000.00

        $this->actingAs($user)
            ->get('/reports?preset=today&metric=cost')
            ->assertOk()
            ->assertSee('Cost / 1M')
            ->assertSee('Eff. Cost / 1M')
            ->assertSee('$1,200.00')
            ->assertSee('$24,000.00');

        Carbon::setTestNow();
    }

    public function test_report_filters_can_be_saved_loaded_and_deleted_as_favorites(): void
    {
        $user = User::factory()->create();
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);

        $this->actingAs($user)
            ->post('/reports/favorites', [
                'favorite_name' => 'Daily OpenAI Cost',
                'preset' => 'today',
                'provider_id' => 'openai',
                'metric' => 'cost',
            ])
            ->assertRedirect('/reports?preset=today&provider_id=openai&metric=cost');

        $favorite = ReportFavorite::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Daily OpenAI Cost', $favorite->name);
        $this->assertSame('today', $favorite->query_json['preset']);
        $this->assertSame('openai', $favorite->query_json['provider_id']);

        $this->actingAs($user)
            ->get('/reports/favorites/'.$favorite->id)
            ->assertRedirect('/reports?preset=today&provider_id=openai&metric=cost&favorite_id='.$favorite->id);

        $this->actingAs($user)
            ->get('/reports?favorite_id='.$favorite->id)
            ->assertOk()
            ->assertSee('Daily OpenAI Cost')
            ->assertSee('Delete Favorite');

        $this->actingAs($user)
            ->delete('/reports/favorites/'.$favorite->id)
            ->assertRedirect('/reports');

        $this->assertDatabaseMissing('report_favorites', ['id' => $favorite->id]);
    }
}
