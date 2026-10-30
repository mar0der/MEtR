<?php

namespace App\Console\Commands;

use App\Console\PriceJobLock;
use App\Models\Device;
use App\Models\UsageEvent;
use App\Services\Pricing\RepriceModelUsage;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Claude events stored before the server kept the 1-hour cache write split
 * were priced as if every cache write used the 5-minute TTL. For devices that
 * cannot resend them (old app, logs gone), price them with the estimated
 * 1-hour share and flag them. Token columns never change; --undo reverts.
 * Devices that still have their logs should do a Full Rescan + Full Resync.
 */
class EstimateOneHourCache extends Command
{
    protected $signature = 'metr:prices:estimate-1h-cache
        {--device=* : Device id (repeatable)}
        {--undo : Price these events without the estimate again}
        {--dry-run : Show what would change, write nothing}';

    protected $description = 'Estimate the 1-hour cache write share for Claude events stored without it';

    public function handle(RepriceModelUsage $reprice): int
    {
        $ids = $this->option('device');
        if ($ids === []) {
            $this->error('Name the devices with --device=<device id>.');

            return self::FAILURE;
        }

        $devices = Device::query()->whereIn('id', $ids)->get();
        $unknown = array_diff($ids, $devices->pluck('id')->all());
        if ($unknown !== []) {
            $this->error('Unknown device id(s): '.implode(', ', $unknown));

            return self::FAILURE;
        }
        foreach ($devices as $device) {
            $this->line("Device {$device->id}: {$device->display_name}");
        }

        $job = fn () => $this->apply($reprice, $ids);

        return $this->option('dry-run') ? $job() : PriceJobLock::run($this, $job);
    }

    /**
     * @param  list<string>  $deviceIds
     */
    private function apply(RepriceModelUsage $reprice, array $deviceIds): int
    {
        $undo = (bool) $this->option('undo');
        $dryRun = (bool) $this->option('dry-run');
        $marker = '%'.UsageEvent::ONE_HOUR_SPLIT_UNREPORTED.'%';
        $storedSince = Carbon::parse(config('metr.one_hour_split_stored_since'));

        $scope = fn ($query) => $query
            ->whereIn('device_id', $deviceIds)
            ->where('cache_write_tokens', '>', 0)
            ->where('cache_write_1h_tokens', 0)
            ->when($undo,
                fn ($q) => $q->where('warnings_json', 'like', $marker),
                // Only events stored before the server kept the split.
                fn ($q) => $q->where('created_at', '<', $storedSince)
                    ->where(fn ($w) => $w->whereNull('warnings_json')->orWhere('warnings_json', 'not like', $marker)),
            );

        $models = $scope(UsageEvent::query()->where('provider_id', 'anthropic')->whereNotNull('model'))
            ->distinct()->pluck('model');

        $rows = [];
        $delta = 0.0;
        foreach ($models as $model) {
            $count = $reprice->handle('anthropic', $model, dryRun: $dryRun, scope: $scope, estimateOneHour: ! $undo);
            $delta += $reprice->lastCostDelta;
            $rows[] = [$model, $count];
        }

        $this->table(['model', $dryRun ? 'events that would change' : 'events changed'], $rows);
        $this->line(sprintf('%sCost change: %+.2f USD%s.', $dryRun ? '[dry run] ' : '', $delta, $dryRun ? ' (nothing written)' : ''));

        return self::SUCCESS;
    }
}
