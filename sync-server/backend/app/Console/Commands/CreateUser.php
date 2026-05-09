<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateUser extends Command
{
    protected $signature = 'metr:user:create {username} {--email=} {--name=}';

    protected $description = 'Create a new MEtR Sync user';

    public function handle(): int
    {
        $username = $this->argument('username');
        $email = $this->option('email');
        $name = $this->option('name') ?? $username;

        if (User::where('username', $username)->exists()) {
            $this->error("User with username '{$username}' already exists.");

            return self::FAILURE;
        }

        $password = $this->secret('Password');
        $confirm = $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("User created: {$user->username} (ID: {$user->id})");

        return self::SUCCESS;
    }
}
