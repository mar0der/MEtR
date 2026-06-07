<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('sync', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return Limit::perMinute(60)->by('sync:'.$key);
        });

        RateLimiter::for('dashboard-api', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return Limit::perMinute(120)->by('dash-api:'.$key);
        });

        RateLimiter::for('web', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return Limit::perMinute(120)->by('web:'.$key);
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by('login:'.$request->ip());
        });
    }
}
