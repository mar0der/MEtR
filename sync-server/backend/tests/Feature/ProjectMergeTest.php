<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Project;
use App\Models\Provider;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
    }

    public function test_project_merge_moves_events_and_roots(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $source = Project::factory()->create(['user_id' => $user->id, 'canonical_name' => 'OldName']);
        $target = Project::factory()->create(['user_id' => $user->id, 'canonical_name' => 'NewName']);
        $device = Device::factory()->create(['user_id' => $user->id]);

        UsageEvent::factory()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'project_id' => $source->id,
            'provider_id' => 'openai',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/projects/{$source->id}/merge", [
                'target_project_id' => $target->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('usage_events', [
            'project_id' => $target->id,
        ]);

        $this->assertDatabaseHas('projects', [
            'id' => $source->id,
            'active' => false,
        ]);
    }
}
