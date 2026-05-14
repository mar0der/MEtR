<?php

declare(strict_types=1);

use App\Http\Controllers\Web\WebController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['ok' => true]));

Route::get('/updates/{filename}', function (string $filename) {
    $path = storage_path('app/updates/'.$filename);
    if (! file_exists($path)) {
        abort(404);
    }
    return response()->file($path);
})->where('filename', '.*');

Route::get('/download', [WebController::class, 'download'])->name('download');

Route::get('/login', [WebController::class, 'loginForm'])->name('login');
Route::post('/login', [WebController::class, 'login']);

Route::middleware('auth')->group(function () {
    Route::post('/logout', [WebController::class, 'logout']);
    Route::get('/dashboard', [WebController::class, 'dashboard']);
    Route::get('/reports', [WebController::class, 'reports']);
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
    return redirect('/download');
});
