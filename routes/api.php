<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\MidtransWebhookController;
use App\Http\Controllers\Api\v1\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System & Monitoring Routes
|--------------------------------------------------------------------------
*/
Route::get('/health', [HealthController::class, 'check'])->name('api.health');
Route::get('/metrics', [MetricsController::class, 'index'])->name('api.metrics');

/*
|--------------------------------------------------------------------------
| API Version 1 Routes
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {

    // Public Authentication Routes
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->name('api.v1.auth.register');
        Route::post('/login', [AuthController::class, 'login'])->name('api.v1.auth.login');

        // Protected Authentication Routes
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        });
    });

    // Public Plans Endpoint
    Route::get('/plans', [SubscriptionController::class, 'plans'])->name('api.v1.plans.index');

    // Protected Subscription Endpoints
    Route::middleware('auth:sanctum')->prefix('subscriptions')->group(function () {
        Route::post('/subscribe', [SubscriptionController::class, 'subscribe'])->name('api.v1.subscriptions.subscribe');
        Route::get('/current', [SubscriptionController::class, 'current'])->name('api.v1.subscriptions.current');
    });

    // Public Midtrans Webhook Callback (Protected by SHA-512 Signature & Concurrency Locks)
    Route::post('/midtrans/webhook', [MidtransWebhookController::class, 'handle'])->name('api.v1.midtrans.webhook');

});
