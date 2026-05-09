<?php

namespace Database\Factories;

use App\Models\AccountAttributionRule;
use App\Models\Provider;
use App\Models\ProviderAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountAttributionRuleFactory extends Factory
{
    protected $model = AccountAttributionRule::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider_id' => Provider::factory(),
            'provider_account_id' => ProviderAccount::factory(),
            'priority' => fake()->numberBetween(1, 100),
            'enabled' => true,
        ];
    }
}
