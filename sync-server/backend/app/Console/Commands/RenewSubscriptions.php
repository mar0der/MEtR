<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RenewSubscriptions extends Command
{
    protected $signature = 'metr:subscriptions:renew';

    protected $description = 'Generate future subscription instances for autorenewing plans.';

    public function handle(): int
    {
        $today = now()->startOfDay();
        $query = Subscription::where('autorenew', true)
            ->where('active', true)
            ->whereDate('ended_on', '<', $today)
            ->orderBy('user_id')
            ->orderBy('provider_id')
            ->orderBy('plan_name')
            ->orderBy('started_on');

        $renewed = 0;
        $safety = 0;

        foreach ($query->cursor() as $subscription) {
            $safety++;
            if ($safety > 10_000) {
                $this->warn('Safety limit reached; stopping.');
                break;
            }

            $anchor = (int) $subscription->billing_anchor_day ?: 1;
            $currentEnd = $subscription->ended_on->startOfDay();
            $providerId = $subscription->provider_id;
            $planName = $subscription->plan_name;
            $userId = $subscription->user_id;

            while ($currentEnd->lessThan($today)) {
                $nextStart = $currentEnd->copy()->addDay();
                $nextEnd = $this->cycleEnd($nextStart, $anchor);

                $overlap = Subscription::where('user_id', $userId)
                    ->where('provider_id', $providerId)
                    ->where('plan_name', $planName)
                    ->where('active', true)
                    ->where(function ($q) use ($nextStart, $nextEnd) {
                        $q->whereDate('started_on', '<=', $nextEnd)
                            ->whereDate('ended_on', '>=', $nextStart);
                    })
                    ->exists();

                if ($overlap) {
                    $this->warn("Overlap detected for user {$userId} / {$providerId} / {$planName}; stopping renewal.");
                    break 2;
                }

                $renewalPrice = $subscription->renewal_price ?? $subscription->monthly_price;

                Subscription::create([
                    'user_id' => $userId,
                    'provider_id' => $providerId,
                    'provider_account_id' => $subscription->provider_account_id,
                    'plan_name' => $planName,
                    'monthly_price' => $renewalPrice,
                    'renewal_price' => $renewalPrice,
                    'currency' => $subscription->currency,
                    'billing_anchor_day' => $anchor,
                    'started_on' => $nextStart,
                    'ended_on' => $nextEnd,
                    'active' => true,
                    'autorenew' => true,
                    'notes' => $subscription->notes,
                ]);

                $renewed++;
                $currentEnd = $nextEnd;
            }
        }

        $this->info("Renewed {$renewed} subscription instance(s).");

        return self::SUCCESS;
    }

    private function cycleEnd(Carbon $start, int $anchor): Carbon
    {
        $nextStart = $start->copy()->addMonthNoOverflow()->day(min($anchor, $start->copy()->addMonthNoOverflow()->daysInMonth));

        return $nextStart->subDay();
    }
}
