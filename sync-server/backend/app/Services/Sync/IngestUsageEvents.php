<?php

namespace App\Services\Sync;

use App\Models\Conversation;
use App\Models\Device;
use App\Models\SyncBatch;
use App\Models\UsageEvent;
use App\Services\Accounts\AttributeProviderAccount;
use App\Services\Pricing\CalculateUsageCost;
use App\Services\Pricing\ResolveModelPrice;
use App\Services\Projects\ResolveProject;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IngestUsageEvents
{
    public function __construct(
        private ResolveProject $resolveProject,
        private ResolveModelPrice $resolveModelPrice,
        private CalculateUsageCost $calculateUsageCost,
        private AttributeProviderAccount $attributeProviderAccount,
    ) {}

    /**
     * @param  array<int, array>  $events
     * @return array{batch_id: string, received: int, inserted: int, updated: int, duplicates: int}
     */
    public function handle(Device $device, string $clientBatchId, array $events): array
    {
        $syncBatch = SyncBatch::updateOrCreate(
            [
                'device_id' => $device->id,
                'client_batch_id' => $clientBatchId,
            ],
            [
                'user_id' => $device->user_id,
                'direction' => 'upload',
                'status' => 'received',
                'event_count' => count($events),
            ]
        );

        $inserted = 0;
        $updated = 0;
        $duplicates = 0;

        DB::transaction(function () use ($device, $events, &$inserted, &$updated, &$duplicates) {
            foreach ($events as $event) {
                $result = $this->upsertEvent($device, $event);
                if ($result === 'inserted') {
                    $inserted++;
                } elseif ($result === 'updated') {
                    $updated++;
                } else {
                    $duplicates++;
                }
            }
        });

        $syncBatch->update([
            'status' => 'processed',
            'event_count' => $inserted + $updated,
        ]);

        return [
            'batch_id' => $syncBatch->id,
            'received' => count($events),
            'inserted' => $inserted,
            'updated' => $updated,
            'duplicates' => $duplicates,
            'server_time' => now()->toIso8601String(),
        ];
    }

    private function upsertEvent(Device $device, array $event): string
    {
        $tokens = $event['tokens'] ?? [];
        $totalTokens = array_sum($tokens);

        if ($totalTokens <= 0) {
            Log::info('Skipping zero-token event', [
                'device_id' => $device->id,
                'source_event_id' => $event['source_event_id'] ?? null,
            ]);

            return 'duplicate'; // Treat as skipped/duplicate for counting purposes
        }

        $existing = UsageEvent::where([
            'device_id' => $device->id,
            'source_event_id' => $event['source_event_id'],
        ])->first();

        $timestamp = Carbon::parse($event['timestamp']);
        $providerId = $event['provider_id'];
        $model = $event['model'] ?? null;

        $project = null;
        if (! empty($event['project']['root_path'])) {
            $project = $this->resolveProject->handle($device, $event['project']['root_path'], $providerId);
        }

        $conversation = null;
        if (! empty($event['conversation']['external_conversation_id'])) {
            $conversation = $this->resolveConversation(
                $device,
                $providerId,
                $project?->id,
                $event['conversation']['external_conversation_id'],
                $event['conversation']['display_name'] ?? null,
                $timestamp,
            );
        }

        $attribution = $this->attributeProviderAccount->handle(
            userId: $device->user_id,
            deviceId: $device->id,
            providerId: $providerId,
            model: $model,
            projectId: $project?->id,
            timestamp: $timestamp,
        );

        $price = null;
        $costData = [
            'cost' => null,
            'pricing_match_confidence' => 'missing',
        ];

        if ($model) {
            $price = $this->resolveModelPrice->handle($providerId, $model, $timestamp);
            if ($price) {
                // Server recalculates using the same provider-aware logic as the desktop app.
                // Keep in sync with src-tauri/src/lib.rs calculate_cost()
                $costData = $this->calculateUsageCost->handle($price, $tokens, $providerId);
            } elseif (($tokens['unknown'] ?? 0) === 0 && isset($event['client_cost']['official_api_cost_usd'])) {
                $costData = [
                    'cost' => (float) $event['client_cost']['official_api_cost_usd'],
                    'pricing_match_confidence' => 'client_'.($event['client_cost']['pricing_match_confidence'] ?? 'provided'),
                ];
            }
        }

        $payload = [
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'provider_id' => $providerId,
            'provider_account_id' => $attribution['provider_account_id'],
            'account_attribution_confidence' => $attribution['confidence'],
            'account_attribution_reason' => $attribution['reason'],
            'project_id' => $project?->id,
            'conversation_id' => $conversation?->id,
            'source_event_hash' => $event['source_event_hash'],
            'source_file_hash' => $event['source_file_hash'] ?? null,
            'source_offset' => $event['source_offset'] ?? null,
            'timestamp' => $timestamp,
            'model' => $model,
            'input_tokens' => $tokens['input'] ?? 0,
            'output_tokens' => $tokens['output'] ?? 0,
            'cached_input_tokens' => $tokens['cached_input'] ?? 0,
            'cache_write_tokens' => $tokens['cache_write'] ?? 0,
            'cache_read_tokens' => $tokens['cache_read'] ?? 0,
            'reasoning_tokens' => $tokens['reasoning'] ?? 0,
            'tool_tokens' => $tokens['tool'] ?? 0,
            'unknown_tokens' => $tokens['unknown'] ?? 0,
            'official_api_cost_usd' => $costData['cost'],
            'model_price_id' => $price?->id,
            'pricing_match_confidence' => $costData['pricing_match_confidence'],
            'warnings_json' => ! empty($event['warnings']) ? json_encode($event['warnings']) : null,
            'client_created_at' => isset($event['client_created_at']) ? Carbon::parse($event['client_created_at']) : null,
            'client_updated_at' => isset($event['client_updated_at']) ? Carbon::parse($event['client_updated_at']) : null,
            'updated_at' => now(),
        ];

        if ($existing) {
            $existing->update($payload);

            return 'updated';
        }

        $payload['source_event_id'] = $event['source_event_id'];
        $payload['created_at'] = now();

        UsageEvent::create($payload);

        return 'inserted';
    }

    private function resolveConversation(
        Device $device,
        string $providerId,
        ?string $projectId,
        string $externalId,
        ?string $displayName,
        Carbon $timestamp,
    ): Conversation {
        $conversation = Conversation::where([
            'user_id' => $device->user_id,
            'provider_id' => $providerId,
            'device_id' => $device->id,
            'external_conversation_id' => $externalId,
        ])->first();

        if ($conversation) {
            $conversation->update([
                'project_id' => $projectId ?? $conversation->project_id,
                'display_name' => $displayName ?? $conversation->display_name,
                'last_seen_at' => $timestamp,
            ]);

            return $conversation;
        }

        return Conversation::create([
            'user_id' => $device->user_id,
            'provider_id' => $providerId,
            'device_id' => $device->id,
            'project_id' => $projectId,
            'external_conversation_id' => $externalId,
            'display_name' => $displayName,
            'started_at' => $timestamp,
            'last_seen_at' => $timestamp,
        ]);
    }
}
