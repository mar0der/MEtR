<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Provider;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_totals_per_device_and_provider_with_duplicate_checks(): void
    {
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
        Provider::factory()->create(['id' => 'anthropic', 'display_name' => 'Anthropic']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mac = Device::factory()->create(['user_id' => $user->id, 'device_uuid' => 'mac', 'display_name' => 'macos-mac']);
        $linux = Device::factory()->create(['user_id' => $user->id, 'device_uuid' => 'linux', 'display_name' => 'linux-the20th']);
        $foreign = Device::factory()->create(['user_id' => $other->id, 'device_uuid' => 'x']);

        $event = fn (Device $device, string $provider, string $hash, int $input) => UsageEvent::factory()->create([
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'provider_id' => $provider,
            'source_event_hash' => $hash,
            'input_tokens' => $input,
            'output_tokens' => 1,
            'timestamp' => '2026-10-29 10:00:00',
        ]);
        $event($mac, 'openai', 'h1', 100);
        $event($mac, 'openai', 'h2', 200);
        $event($mac, 'openai', 'h2', 200);      // same line twice on one device
        $event($mac, 'anthropic', 'c1', 10);
        $event($linux, 'openai', 'h1', 100);    // same line on another device
        $event($foreign, 'openai', 'z', 5000);  // another user's data is never included

        $response = $this->actingAs($user)->getJson('/api/v1/sync/reconcile')->assertOk();

        $totals = collect($response->json('totals'))
            ->mapWithKeys(fn ($row) => [$row['device_uuid'].'/'.$row['provider_id'] => [$row['events'], $row['input_tokens']]]);
        $this->assertEquals([
            'linux/openai' => [1, 100],
            'mac/anthropic' => [1, 10],
            'mac/openai' => [3, 500],
        ], $totals->sortKeys()->all());
        $this->assertEquals(
            [['device_id' => $mac->id, 'provider_id' => 'openai', 'lines' => 1, 'extra_rows' => 1]],
            $response->json('duplicates.same_device')
        );
        $this->assertEquals(
            [['provider_id' => 'openai', 'lines' => 1, 'rows' => 2]],
            $response->json('duplicates.cross_device')
        );
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/sync/reconcile')->assertUnauthorized();
    }
}
