<?php

namespace App\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * One price job at a time across all containers: metr:prices:update and
 * metr:prices:reprice share this lock (on the shared cache store, Redis in
 * production). The update keeps it while it calls the reprice in-process.
 */
final class PriceJobLock
{
    /** A killed job frees the lock after this long. */
    private const SECONDS = 6 * 3600;

    private static bool $held = false;

    public static function run(Command $command, Closure $job): int
    {
        if (self::$held) {
            return $job();
        }

        $lock = Cache::lock('metr:prices', self::SECONDS);
        if (! $lock->get()) {
            $command->error('Another price job (metr:prices:update or metr:prices:reprice) is running. Try again when it has finished.');

            return Command::FAILURE;
        }

        self::$held = true;
        try {
            return $job();
        } finally {
            self::$held = false;
            $lock->release();
        }
    }
}
