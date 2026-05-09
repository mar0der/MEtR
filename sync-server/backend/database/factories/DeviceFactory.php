<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_uuid' => fake()->uuid(),
            'display_name' => fake()->word(),
            'platform' => fake()->randomElement(['macos', 'windows', 'linux']),
        ];
    }
}
