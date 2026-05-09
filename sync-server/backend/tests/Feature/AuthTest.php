<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_password_login_returns_sanctum_token(): void
    {
        $user = User::factory()->create([
            'username' => 'petar',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'petar',
            'password' => 'secret123',
            'device_name' => 'Test Device',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'username']]);
    }

    public function test_login_accepts_email(): void
    {
        $user = User::factory()->create([
            'username' => 'petar',
            'email' => 'petar@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'petar@example.com',
            'password' => 'secret123',
            'device_name' => 'Test Device',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
