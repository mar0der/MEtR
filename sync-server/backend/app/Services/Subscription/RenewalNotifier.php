<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class RenewalNotifier
{
    /**
     * Return auto-renewing subscriptions whose billing cycle ends today or
     * tomorrow (i.e. renewing within the next ~48 hours).
     */
    public function upcoming(User $user): Collection
    {
        $today = now()->startOfDay();
        $tomorrow = now()->addDay()->endOfDay();

        return Subscription::where('subscriptions.user_id', $user->id)
            ->where('subscriptions.autorenew', true)
            ->whereNotNull('subscriptions.ended_on')
            ->whereDate('subscriptions.ended_on', '>=', $today)
            ->whereDate('subscriptions.ended_on', '<=', $tomorrow)
            ->orderBy('subscriptions.ended_on')
            ->with('provider')
            ->get();
    }
}
