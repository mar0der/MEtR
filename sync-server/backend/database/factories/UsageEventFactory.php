<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Provider;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_id' => Device::factory(),
            'provider_id' => Provider::factory(),
            'source_event_id' => fake()->uuid(),
            'source_event_hash' => fake()->sha256(),
            'timestamp' => fake()->dateTimeBetween('-30 days', 'now'),
            'input_tokens' => fake()->numberBetween(0, 10000),
            'output_tokens' => fake()->numberBetween(0, 5000),
            'cached_input_tokens' => 0,
            'cache_write_tokens' => 0,
            'cache_read_tokens' => 0,
            'reasoning_tokens' => 0,
            'tool_tokens' => 0,
            'unknown_tokens' => 0,
        ];
    }
}
