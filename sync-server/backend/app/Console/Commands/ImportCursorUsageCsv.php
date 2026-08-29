<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\User;
use App\Services\Cursor\CursorModelPrices;
use App\Services\Cursor\CursorUsageCsvParser;
use App\Services\Pricing\ResolveModelPrice;
use App\Services\Sync\IngestUsageEvents;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportCursorUsageCsv extends Command
{
    protected $signature = 'metr:import-cursor-csv
        {path : Path to a Cursor dashboard usage-events CSV}
        {--username= : MEtR username that owns the events}
        {--device-uuid= : Existing device_uuid to attach events to}
        {--project-path=/Cursor : Fake project root; canonical name is the last path segment}
        {--seed-prices : Upsert Cursor catalog prices before import}
        {--dry-run : Parse and report without writing events}';

    protected $description = 'Import a Cursor usage-events CSV into the sync server under a Cursor project';

    public function handle(
        CursorUsageCsvParser $parser,
        CursorModelPrices $cursorPrices,
        ResolveModelPrice $resolvePrice,
        IngestUsageEvents $ingest,
    ): int {
        $path = $this->argument('path');
        if (! is_readable($path)) {
            $this->error("File not readable: {$path}");

            return self::FAILURE;
        }

        $parsed = $parser->parse($path, (string) $this->option('project-path'));
        $this->info('Parsed '.count($parsed['events']).' events, skipped '.$parsed['skipped'].' empty/errored rows.');

        if ($this->option('seed-prices')) {
            $seeded = $cursorPrices->seed();
            $this->info("Seeded {$seeded} Cursor price rows.");
        }

        $this->table(
            ['CSV model', 'Rows', 'Catalog match'],
            $this->priceRows($parsed['models'], $resolvePrice)
        );

        if ($this->option('dry-run')) {
            $this->warn('Dry run: no events written.');

            return self::SUCCESS;
        }

        $username = $this->option('username');
        if (! $username) {
            $this->error('Provide --username to import.');

            return self::FAILURE;
        }

        $user = User::where('username', $username)->first();
        if (! $user) {
            $this->error("User not found: {$username}");

            return self::FAILURE;
        }

        $device = $this->resolveDevice($user, $this->option('device-uuid'));
        if (! $device) {
            return self::FAILURE;
        }

        $batchId = 'cursor-csv-'.substr($parsed['source_file_hash'], 0, 16).'-'.now()->format('YmdHis');
        $chunks = array_chunk($parsed['events'], 200);
        $inserted = 0;
        $updated = 0;
        $duplicates = 0;

        foreach ($chunks as $i => $chunk) {
            $result = $ingest->handle($device, $batchId.'-'.$i, $chunk);
            $inserted += $result['inserted'];
            $updated += $result['updated'];
            $duplicates += $result['duplicates'];
            $this->info('Chunk '.($i + 1).'/'.count($chunks).': inserted '.$result['inserted'].', updated '.$result['updated']);
        }

        $this->info("Done. inserted={$inserted} updated={$updated} skipped_zero={$duplicates}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $models
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function priceRows(array $models, ResolveModelPrice $resolvePrice): array
    {
        $now = Carbon::now();
        $rows = [];
        arsort($models);
        foreach ($models as $model => $count) {
            $price = $resolvePrice->handle('cursor', $model, $now);
            $rows[] = [
                $model,
                $count,
                $price ? $price->model : 'MISSING',
            ];
        }

        return $rows;
    }

    private function resolveDevice(User $user, ?string $deviceUuid): ?Device
    {
        $query = Device::where('user_id', $user->id);
        if ($deviceUuid) {
            $device = (clone $query)->where('device_uuid', $deviceUuid)->first();
            if (! $device) {
                $this->error("Device {$deviceUuid} not found for {$user->username}.");
                $this->listDevices($user);

                return null;
            }

            return $device;
        }

        $devices = $query->orderBy('last_seen_at', 'desc')->get();
        if ($devices->isEmpty()) {
            $this->error("User {$user->username} has no devices. Sync the desktop app once, or pass --device-uuid after registering.");

            return null;
        }
        if ($devices->count() > 1) {
            $this->warn('Multiple devices; using the most recently seen. Pass --device-uuid to pick one.');
            $this->listDevices($user);
        }

        return $devices->first();
    }

    private function listDevices(User $user): void
    {
        $rows = Device::where('user_id', $user->id)
            ->get(['device_uuid', 'display_name', 'platform', 'last_seen_at'])
            ->map(fn (Device $d) => [
                $d->device_uuid,
                $d->display_name,
                $d->platform,
                $d->last_seen_at,
            ])
            ->all();
        $this->table(['device_uuid', 'display_name', 'platform', 'last_seen_at'], $rows);
    }
}
