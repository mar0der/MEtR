<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;

final class CalculateSubscriptionCost
{
    /**
     * Calculate prorated subscription cost for a user across a date range.
     *
     * @param array<string>|null $providerIds
     *
     * @return array{
     *     total: float,
     *     by_provider: array<string, float>,
     *     daily_total: array<string, float>,
     * }
     */
    public function forPeriod(User $user, Carbon $from, Carbon $to, ?array $providerIds = null): array
    {
        $query = Subscription::query()
            ->where('subscriptions.user_id', $user->id)
            ->where('subscriptions.active', true)
            ->whereNotNull('subscriptions.started_on')
            ->whereNotNull('subscriptions.ended_on')
            ->whereDate('subscriptions.started_on', '<=', $to)
            ->whereDate('subscriptions.ended_on', '>=', $from);

        if ($providerIds !== null && $providerIds !== []) {
            $query->whereIn('subscriptions.provider_id', $providerIds);
        }

        $subscriptions = $query->get();

        $total = 0.0;
        $byProvider = [];
        $dailyTotal = [];

        foreach ($subscriptions as $subscription) {
            /** @var Carbon $start */
            $start = $subscription->started_on->startOfDay();
            /** @var Carbon $end */
            $end = $subscription->ended_on->endOfDay();

            $overlapStart = $from->copy()->max($start);
            $overlapEnd = $to->copy()->min($end);

            if ($overlapStart->greaterThan($overlapEnd)) {
                continue;
            }

            $cycleDays = (int) $start->diffInDays($end) + 1;
            if ($cycleDays <= 0) {
                continue;
            }

            $monthlyPrice = (float) $subscription->monthly_price;
            $dailyRate = $monthlyPrice / $cycleDays;

            $overlapDays = (int) $overlapStart->diffInDays($overlapEnd) + 1;
            $cost = $overlapDays * $dailyRate;

            $providerId = $subscription->provider_id;
            $byProvider[$providerId] = ($byProvider[$providerId] ?? 0.0) + $cost;
            $total += $cost;

            $cursor = $overlapStart->copy()->startOfDay();
            while ($cursor->lessThanOrEqualTo($overlapEnd)) {
                $key = $cursor->format('Y-m-d');
                $dailyTotal[$key] = ($dailyTotal[$key] ?? 0.0) + $dailyRate;
                $cursor->addDay();
            }
        }

        return [
            'total' => $total,
            'by_provider' => $byProvider,
            'daily_total' => $dailyTotal,
        ];
    }
}
