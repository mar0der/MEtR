<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
    }

    public function test_device_registration_is_idempotent(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $payload = [
            'device_uuid' => 'uuid-123',
            'display_name' => 'MacBook',
            'platform' => 'macos',
        ];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/devices/register', $payload)
            ->assertOk();

        $this->assertDatabaseCount('devices', 1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/devices/register', array_merge($payload, ['display_name' => 'Updated MacBook']))
            ->assertOk();

        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseHas('devices', ['display_name' => 'Updated MacBook']);
    }
}
