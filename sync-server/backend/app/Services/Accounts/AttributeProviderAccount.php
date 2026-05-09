<?php

namespace App\Services\Accounts;

use App\Models\AccountAttributionRule;
use App\Models\Device;
use App\Models\ProviderAccount;
use Carbon\Carbon;

class AttributeProviderAccount
{
    /**
     * @return array{provider_account_id: string|null, confidence: string, reason: string|null}
     */
    public function handle(
        int $userId,
        string $deviceId,
        string $providerId,
        ?string $model,
        ?string $projectId,
        Carbon $timestamp,
        ?string $accountHintHash = null,
        ?string $manualOverride = null,
    ): array {
        if ($manualOverride) {
            return [
                'provider_account_id' => $manualOverride,
                'confidence' => 'manual',
                'reason' => 'manual_override',
            ];
        }

        if ($accountHintHash) {
            $account = ProviderAccount::where([
                'user_id' => $userId,
                'provider_id' => $providerId,
                'external_account_hint_hash' => $accountHintHash,
            ])->first();

            if ($account) {
                return [
                    'provider_account_id' => $account->id,
                    'confidence' => 'exact',
                    'reason' => 'account_hint_hash',
                ];
            }
        }

        $rules = AccountAttributionRule::where('user_id', $userId)
            ->where('enabled', true)
            ->orderBy('priority', 'asc')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->ruleMatches($rule, $deviceId, $providerId, $model, $projectId, $timestamp)) {
                continue;
            }

            return [
                'provider_account_id' => $rule->provider_account_id,
                'confidence' => 'rule',
                'reason' => 'attribution_rule:'.$rule->id,
            ];
        }

        // Fallback to default device mapping
        $defaultAccount = ProviderAccount::where([
            'user_id' => $userId,
            'provider_id' => $providerId,
            'default_device_id' => $deviceId,
        ])->first();

        if ($defaultAccount) {
            return [
                'provider_account_id' => $defaultAccount->id,
                'confidence' => 'device_default',
                'reason' => 'default_device_mapping',
            ];
        }

        return [
            'provider_account_id' => null,
            'confidence' => 'unknown',
            'reason' => null,
        ];
    }

    private function ruleMatches(
        AccountAttributionRule $rule,
        string $deviceId,
        string $providerId,
        ?string $model,
        ?string $projectId,
        Carbon $timestamp,
    ): bool {
        if ($rule->provider_id && $rule->provider_id !== $providerId) {
            return false;
        }

        if ($rule->device_id && $rule->device_id !== $deviceId) {
            return false;
        }

        if ($rule->project_id && $rule->project_id !== $projectId) {
            return false;
        }

        if ($rule->model_pattern && $model !== null) {
            if (! fnmatch($rule->model_pattern, $model, FNM_CASEFOLD)) {
                return false;
            }
        }

        if ($rule->starts_at && $timestamp->lt($rule->starts_at)) {
            return false;
        }

        if ($rule->ends_at && $timestamp->gte($rule->ends_at)) {
            return false;
        }

        return true;
    }
}
