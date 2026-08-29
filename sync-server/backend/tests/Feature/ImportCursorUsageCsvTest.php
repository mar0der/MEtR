<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Project;
use App\Models\Provider;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Cursor\CursorModelPrices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportCursorUsageCsvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Provider::factory()->create(['id' => 'cursor', 'display_name' => 'Cursor']);
    }

    public function test_dry_run_does_not_insert_events(): void
    {
        (new CursorModelPrices)->seed();
        $user = User::factory()->create(['username' => 'petar']);
        Device::factory()->create(['user_id' => $user->id, 'platform' => 'macos']);

        $this->artisan('metr:import-cursor-csv', [
            'path' => base_path('tests/fixtures/cursor/usage-events.csv'),
            '--username' => 'petar',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, UsageEvent::count());
    }

    public function test_import_creates_cursor_project_and_prices_known_models(): void
    {
        $user = User::factory()->create(['username' => 'petar']);
        $device = Device::factory()->create([
            'user_id' => $user->id,
            'device_uuid' => 'dev-1',
            'platform' => 'macos',
        ]);

        $this->artisan('metr:import-cursor-csv', [
            'path' => base_path('tests/fixtures/cursor/usage-events.csv'),
            '--username' => 'petar',
            '--device-uuid' => 'dev-1',
            '--seed-prices' => true,
        ])->assertSuccessful();

        $this->assertSame(3, UsageEvent::count());
        $this->assertDatabaseHas('projects', [
            'user_id' => $user->id,
            'canonical_name' => 'Cursor',
        ]);

        $project = Project::where('canonical_name', 'Cursor')->first();
        $this->assertNotNull($project);
        $this->assertSame(3, UsageEvent::where('project_id', $project->id)->count());

        $grok = UsageEvent::where('model', 'cursor-grok-4.6-high')->first();
        $this->assertNotNull($grok);
        $this->assertSame('cursor', $grok->provider_id);
        $this->assertSame('exact', $grok->pricing_match_confidence);
        $this->assertEqualsWithDelta(0.0036, (float) $grok->official_api_cost_usd, 0.0000001);

        $auto = UsageEvent::where('model', 'auto')->first();
        $this->assertNotNull($auto);
        $this->assertSame('missing', $auto->pricing_match_confidence);
        $this->assertNull($auto->official_api_cost_usd);

        $this->artisan('metr:import-cursor-csv', [
            'path' => base_path('tests/fixtures/cursor/usage-events.csv'),
            '--username' => 'petar',
            '--device-uuid' => $device->device_uuid,
        ])->assertSuccessful();

        $this->assertSame(3, UsageEvent::count());
    }
}
