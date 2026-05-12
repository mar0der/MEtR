<?php

use App\Http\Controllers\Api\V1\AccountAttributionRuleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProviderAccountController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\UpdateController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::get('/update/{target}/{arch}/{currentVersion}', [UpdateController::class, 'manifest']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::post('/devices/register', [DeviceController::class, 'register']);

        Route::post('/sync/events', [SyncController::class, 'events']);
        Route::post('/sync/subscriptions', [SyncController::class, 'subscriptions']);
        Route::post('/sync/pricing', [SyncController::class, 'pricing']);
        Route::get('/sync/settings', [SyncController::class, 'settings']);

        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/dashboard/by-device', [DashboardController::class, 'byDevice']);
        Route::get('/dashboard/by-project', [DashboardController::class, 'byProject']);
        Route::get('/dashboard/by-provider-account', [DashboardController::class, 'byProviderAccount']);
        Route::get('/dashboard/by-model', [DashboardController::class, 'byModel']);

        Route::apiResource('provider-accounts', ProviderAccountController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('account-attribution-rules', AccountAttributionRuleController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('/account-attribution-rules/reapply', [AccountAttributionRuleController::class, 'reapply']);

        Route::apiResource('subscriptions', SubscriptionController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::get('/projects', [ProjectController::class, 'index']);
        Route::patch('/projects/{id}', [ProjectController::class, 'update']);
        Route::post('/projects/{id}/merge', [ProjectController::class, 'merge']);

        Route::get('/pricing', [PricingController::class, 'index']);
        Route::post('/pricing', [PricingController::class, 'store']);
        Route::patch('/pricing/{id}', [PricingController::class, 'update']);
    });
});
