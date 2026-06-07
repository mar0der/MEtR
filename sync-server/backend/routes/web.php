<?php

declare(strict_types=1);

use App\Http\Controllers\Web\WebController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $checks = [
        'database' => true,
        'redis' => true,
    ];
    try {
        DB::select('SELECT 1');
    } catch (\Throwable $e) {
        $checks['database'] = false;
    }
    try {
        \Illuminate\Support\Facades\Redis::ping();
    } catch (\Throwable $e) {
        $checks['redis'] = false;
    }
    $healthy = !in_array(false, $checks, true);
    return response()->json([
        'status' => $healthy ? 'ok' : 'degraded',
        'checks' => $checks,
        'timestamp' => now()->toIso8601String(),
    ], $healthy ? 200 : 503);
});

Route::get('/updates/{filename}', function (string $filename) {
    $path = storage_path('app/updates/'.$filename);
    if (! file_exists($path)) {
        abort(404);
    }
    return response()->file($path);
})->where('filename', '.*');

Route::get('/download', [WebController::class, 'download'])->name('download');

Route::get('/register', [WebController::class, 'registerForm'])->name('register');
Route::post('/register', [WebController::class, 'register'])->middleware('throttle:10,1');

Route::get('/login', [WebController::class, 'loginForm'])->name('login');
Route::post('/login', [WebController::class, 'login'])->middleware('throttle:10,1');

Route::get('/forgot-password', [WebController::class, 'forgotPasswordForm'])->name('password.request');
Route::post('/forgot-password', [WebController::class, 'sendResetLink'])->middleware('throttle:5,1');
Route::get('/reset-password/{token}', [WebController::class, 'resetPasswordForm'])->name('password.reset');
Route::post('/reset-password', [WebController::class, 'resetPassword']);

Route::get('/demo-login', [WebController::class, 'demoLogin'])->middleware('throttle:10,1');

Route::middleware(['auth', 'throttle:120,1'])->group(function () {
    Route::post('/logout', [WebController::class, 'logout']);
    Route::get('/dashboard', [WebController::class, 'dashboard']);
    Route::get('/reports', [WebController::class, 'reports']);
    Route::post('/reports/favorites', [WebController::class, 'storeReportFavorite']);
    Route::get('/reports/favorites/{id}', [WebController::class, 'loadReportFavorite']);
    Route::delete('/reports/favorites/{id}', [WebController::class, 'deleteReportFavorite']);
    Route::get('/devices', [WebController::class, 'devices']);
    Route::post('/devices/{id}/alias', [WebController::class, 'updateDeviceAlias']);
    Route::post('/devices/{id}/delete', [WebController::class, 'deleteDevice']);
    Route::get('/provider-accounts', [WebController::class, 'providerAccounts']);
    Route::get('/subscriptions', [WebController::class, 'subscriptions']);
    Route::get('/projects', [WebController::class, 'projects']);
    Route::get('/pricing', [WebController::class, 'pricing']);
    Route::get('/settings', [WebController::class, 'settings']);
    Route::post('/settings/clear-data', [WebController::class, 'clearData']);
});

Route::get('/', function () {
    if (auth()->check()) {
        return redirect('/dashboard');
    }
    return view('home');
});
