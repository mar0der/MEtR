<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\ProviderAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProviderAccountFactory extends Factory
{
    protected $model = ProviderAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider_id' => Provider::factory(),
            'label' => fake()->words(3, true),
            'account_type' => fake()->randomElement(['personal', 'team', 'enterprise', 'unknown']),
            'active' => true,
        ];
    }
}
