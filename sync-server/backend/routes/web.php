<?php

declare(strict_types=1);

use App\Http\Controllers\Web\WebController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['ok' => true]));

Route::get('/login', [WebController::class, 'loginForm'])->name('login');
Route::post('/login', [WebController::class, 'login']);

Route::middleware('auth')->group(function () {
    Route::post('/logout', [WebController::class, 'logout']);
    Route::get('/dashboard', [WebController::class, 'dashboard']);
    Route::get('/devices', [WebController::class, 'devices']);
    Route::get('/provider-accounts', [WebController::class, 'providerAccounts']);
    Route::get('/subscriptions', [WebController::class, 'subscriptions']);
    Route::get('/projects', [WebController::class, 'projects']);
    Route::get('/pricing', [WebController::class, 'pricing']);
});

Route::get('/', fn () => redirect('/dashboard'));
