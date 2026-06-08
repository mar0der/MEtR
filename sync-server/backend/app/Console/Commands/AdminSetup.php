<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AdminSetup extends Command
{
    protected $signature = 'metr:admin:setup {email} {password}';
    protected $description = 'Generate bcrypt hash for admin panel credentials';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');
        $hash = Hash::make($password);

        $this->info("Add these lines to your backend .env file:");
        $this->newLine();
        $this->line("ADMIN_EMAIL={$email}");
        $this->line("ADMIN_PASSWORD_HASH={$hash}");
        $this->newLine();
        $this->warn("Restart the container after updating .env for changes to take effect.");

        return self::SUCCESS;
    }
}
