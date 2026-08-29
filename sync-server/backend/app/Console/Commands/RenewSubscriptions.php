<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RenewSubscriptions extends Command
{
    protected $signature = 'metr:subscriptions:renew';

    protected $description = 'Generate future subscription instances for autorenewing plans.';

    public function handle(): int
    {
        $today = now()->startOfDay();

        $latestByGroup = Subscription::query()
            ->where('autorenew', true)
            ->where('active', true)
            ->whereNotNull('ended_on')
            ->orderBy('started_on')
            ->get()
            ->groupBy(fn (Subscription $subscription) => implode("\0", [
                (string) $subscription->user_id,
                $subscription->provider_id,
                $subscription->plan_name,
                (string) ($subscription->provider_account_id ?? ''),
            ]))
            ->map(function ($group) {
                return $group
                    ->sortByDesc(fn (Subscription $subscription) => $subscription->ended_on->timestamp)
                    ->first();
            });

        $renewed = 0;
        $safety = 0;

        foreach ($latestByGroup as $subscription) {
            $currentEnd = $subscription->ended_on->copy()->startOfDay();
            if (! $currentEnd->lessThan($today)) {
                continue;
            }

            $anchor = (int) $subscription->billing_anchor_day ?: 1;
            $template = $subscription;

            while ($currentEnd->lessThan($today)) {
                $safety++;
                if ($safety > 10_000) {
                    $this->warn('Safety limit reached; stopping.');

                    return self::SUCCESS;
                }

                $nextStart = $currentEnd->copy()->addDay();
                $nextEnd = $this->cycleEnd($nextStart, $anchor);

                $overlap = Subscription::where('user_id', $template->user_id)
                    ->where('provider_id', $template->provider_id)
                    ->where('plan_name', $template->plan_name)
                    ->where('active', true)
                    ->where(function ($q) use ($nextStart, $nextEnd) {
                        $q->whereDate('started_on', '<=', $nextEnd)
                            ->whereDate('ended_on', '>=', $nextStart);
                    })
                    ->exists();

                if ($overlap) {
                    $this->warn("Overlap detected for user {$template->user_id} / {$template->provider_id} / {$template->plan_name}; skipping group.");
                    break;
                }

                $renewalPrice = $template->renewal_price ?? $template->monthly_price;

                Subscription::create([
                    'user_id' => $template->user_id,
                    'provider_id' => $template->provider_id,
                    'provider_account_id' => $template->provider_account_id,
                    'plan_name' => $template->plan_name,
                    'monthly_price' => $renewalPrice,
                    'renewal_price' => $renewalPrice,
                    'currency' => $template->currency,
                    'billing_anchor_day' => $anchor,
                    'started_on' => $nextStart,
                    'ended_on' => $nextEnd,
                    'active' => true,
                    'autorenew' => true,
                    'notes' => $template->notes,
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
