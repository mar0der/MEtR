<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

RateLimiter::for('sync', function (Request $request) {
    $key = $request->user()?->id ?: $request->ip();
    return Limit::perMinute(30)->by('sync:'.$key);
});

RateLimiter::for('dashboard-api', function (Request $request) {
    $key = $request->user()?->id ?: $request->ip();
    return Limit::perMinute(120)->by('dash-api:'.$key);
});

RateLimiter::for('web', function (Request $request) {
    $key = $request->user()?->id ?: $request->ip();
    return Limit::perMinute(60)->by('web:'.$key);
});

RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by('login:'.$request->ip());
});
