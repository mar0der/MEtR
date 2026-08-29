<?php

namespace Database\Seeders;

use App\Services\Cursor\CursorModelPrices;
use Illuminate\Database\Seeder;

class CursorModelPriceSeeder extends Seeder
{
    public function run(): void
    {
        $count = (new CursorModelPrices)->seed();
        $this->command?->info("Seeded {$count} Cursor model prices.");
    }
}
