<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
    }

    public function test_event_upload_is_idempotent(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $device = Device::factory()->create(['user_id' => $user->id, 'device_uuid' => 'd1']);

        $event = [
            'device_uuid' => 'd1',
            'client_batch_id' => 'batch-1',
            'events' => [
                [
                    'source_event_id' => 'evt-1',
                    'source_event_hash' => 'hash-1',
                    'request_id' => 'request-1',
                    'message_id' => 'message-1',
                    'account_hint_hash' => 'account-hash-1',
                    'provider_id' => 'openai',
                    'timestamp' => '2026-05-09T09:20:00Z',
                    'model' => 'gpt-5.1',
                    'project' => ['root_path' => '/Users/petarpetkov/Developer/MEtR'],
                    'conversation' => ['external_conversation_id' => 'conv-1'],
                    'tokens' => [
                        'input' => 1000,
                        'output' => 200,
                        'cached_input' => 0,
                        'cache_write' => 0,
                        'cache_read' => 0,
                        'reasoning' => 0,
                        'tool' => 0,
                        'unknown' => 0,
                    ],
                    'client_cost' => [
                        'official_api_cost_usd' => 0.005,
                        'pricing_match_confidence' => 'exact',
                    ],
                    'warnings' => [],
                ],
            ],
        ];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/events', $event)
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('duplicates', 0);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/events', $event)
            ->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('duplicates', 0);

        $this->assertDatabaseHas('usage_events', [
            'source_event_id' => 'evt-1',
            'request_id' => 'request-1',
            'message_id' => 'message-1',
            'account_hint_hash' => 'account-hash-1',
        ]);
    }

    public function test_live_and_archived_legacy_copies_share_one_raw_hash_event(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $device = Device::factory()->create(['user_id' => $user->id, 'device_uuid' => 'd1']);

        $baseEvent = [
            'device_uuid' => 'd1',
            'events' => [[
                'source_event_hash' => 'codex-archive-live-hash',
                'provider_id' => 'openai',
                'timestamp' => '2026-05-09T09:20:00Z',
                'model' => 'gpt-5.1',
                'project' => null,
                'conversation' => null,
                'tokens' => [
                    'input' => 1000,
                    'output' => 200,
                    'cached_input' => 0,
                    'cache_write' => 0,
                    'cache_read' => 0,
                    'reasoning' => 0,
                    'tool' => 0,
                    'unknown' => 0,
                ],
                'warnings' => [],
            ]],
        ];

        $archived = $baseEvent + [
            'client_batch_id' => 'archive-batch',
        ];
        $archived['events'][0]['source_event_id'] = 'archived-copy-id';

        $live = $baseEvent + [
            'client_batch_id' => 'live-batch',
        ];
        $live['events'][0]['source_event_id'] = 'live-copy-id';

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/events', $archived)
            ->assertOk()
            ->assertJsonPath('inserted', 1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/events', $live)
            ->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('inserted', 0);

        $this->assertDatabaseCount('usage_events', 1);
        $this->assertDatabaseHas('usage_events', [
            'source_event_id' => 'archived-copy-id',
            'source_event_hash' => 'codex-archive-live-hash',
        ]);
    }

    public function test_zero_token_events_are_skipped(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $device = Device::factory()->create(['user_id' => $user->id, 'device_uuid' => 'd1']);

        $event = [
            'device_uuid' => 'd1',
            'client_batch_id' => 'batch-1',
            'events' => [
                [
                    'source_event_id' => 'evt-zero',
                    'source_event_hash' => 'hash-zero',
                    'provider_id' => 'openai',
                    'timestamp' => '2026-05-09T09:20:00Z',
                    'model' => 'gpt-5.1',
                    'project' => null,
                    'conversation' => null,
                    'tokens' => [
                        'input' => 0,
                        'output' => 0,
                        'cached_input' => 0,
                        'cache_write' => 0,
                        'cache_read' => 0,
                        'reasoning' => 0,
                        'tool' => 0,
                        'unknown' => 0,
                    ],
                    'warnings' => [],
                ],
            ],
        ];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/events', $event)
            ->assertOk()
            ->assertJsonPath('inserted', 0)
            ->assertJsonPath('duplicates', 1);
    }

    public function test_missing_price_imports_event_with_null_cost(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $device = Device::factory()->create(['user_id' => $user->id, 'device_uuid' => 'd1']);

        $event = [
            'device_uuid' => 'd1',
            'client_batch_id' => 'batch-1',
            'events' => [
                [
                    'source_event_id' => 'evt-2',
                    'source_event_hash' => 'hash-2',
                    'provider_id' => 'openai',
                    'timestamp' => '2026-05-09T09:20:00Z',
                    'model' => 'unknown-model-xyz',
                    'project' => null,
                    'conversation' => null,
                    'tokens' => [
                        'input' => 100,
                        'output' => 50,
                        'cached_input' => 0,
                        'cache_write' => 0,
                        'cache_read' => 0,
                        'reasoning' => 0,
                        'tool' => 0,
                        'unknown' => 0,
                    ],
                    'warnings' => [],
                ],
            ],
        ];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/events', $event)
            ->assertOk()
            ->assertJsonPath('inserted', 1);

        $this->assertDatabaseHas('usage_events', [
            'source_event_id' => 'evt-2',
            'official_api_cost_usd' => null,
            'pricing_match_confidence' => 'missing',
        ]);
    }

    public function test_subscription_sync_is_idempotent(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $payload = [
            'subscriptions' => [
                [
                    'source_subscription_id' => 'local-sub-1',
                    'provider_id' => 'openai',
                    'product_name' => 'ChatGPT Plus / Codex',
                    'monthly_amount' => 20,
                    'currency' => 'usd',
                    'billing_anchor_day' => 9,
                    'enabled' => true,
                ],
            ],
        ];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/subscriptions', $payload)
            ->assertOk()
            ->assertJsonPath('synced', 1);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'source_subscription_id' => 'local-sub-1',
            'provider_id' => 'openai',
            'plan_name' => 'ChatGPT Plus / Codex',
            'monthly_price' => 20,
            'currency' => 'USD',
            'billing_anchor_day' => 9,
            'active' => true,
        ]);

        $payload['subscriptions'][0]['monthly_amount'] = 25;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/subscriptions', $payload)
            ->assertOk()
            ->assertJsonPath('synced', 1);

        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseHas('subscriptions', [
            'source_subscription_id' => 'local-sub-1',
            'monthly_price' => 25,
        ]);
    }
}
