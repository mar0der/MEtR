<?php

namespace Tests\Feature;

use App\Models\AccountAttributionRule;
use App\Models\Device;
use App\Models\Provider;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\Accounts\AttributeProviderAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributionRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Provider::factory()->create(['id' => 'openai', 'display_name' => 'OpenAI']);
        Provider::factory()->create(['id' => 'anthropic', 'display_name' => 'Claude']);
    }

    public function test_attribution_rule_assigns_windows_openai_to_enterprise(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id, 'platform' => 'windows']);
        $account = ProviderAccount::factory()->create([
            'user_id' => $user->id,
            'provider_id' => 'openai',
            'label' => 'OpenAI Enterprise - Windows',
        ]);

        AccountAttributionRule::factory()->create([
            'user_id' => $user->id,
            'provider_id' => 'openai',
            'provider_account_id' => $account->id,
            'device_id' => $device->id,
            'priority' => 10,
            'enabled' => true,
        ]);

        $attributor = new AttributeProviderAccount;
        $result = $attributor->handle(
            userId: $user->id,
            deviceId: $device->id,
            providerId: 'openai',
            model: null,
            projectId: null,
            timestamp: now(),
        );

        $this->assertSame($account->id, $result['provider_account_id']);
        $this->assertSame('rule', $result['confidence']);
    }

    public function test_manual_override_beats_rules(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);
        $account = ProviderAccount::factory()->create([
            'user_id' => $user->id,
            'provider_id' => 'openai',
            'label' => 'Auto Account',
        ]);
        $manualAccount = ProviderAccount::factory()->create([
            'user_id' => $user->id,
            'provider_id' => 'openai',
            'label' => 'Manual Account',
        ]);

        AccountAttributionRule::factory()->create([
            'user_id' => $user->id,
            'provider_id' => 'openai',
            'provider_account_id' => $account->id,
            'priority' => 10,
            'enabled' => true,
        ]);

        $attributor = new AttributeProviderAccount;
        $result = $attributor->handle(
            userId: $user->id,
            deviceId: $device->id,
            providerId: 'openai',
            model: null,
            projectId: null,
            timestamp: now(),
            manualOverride: $manualAccount->id,
        );

        $this->assertSame($manualAccount->id, $result['provider_account_id']);
        $this->assertSame('manual', $result['confidence']);
    }
}
