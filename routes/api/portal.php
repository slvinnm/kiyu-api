<?php

use App\Http\Controllers\Api\Portal\AuditLogController;
use App\Http\Controllers\Api\Portal\DashboardController;
use App\Http\Controllers\Api\Portal\PaymentController;
use App\Http\Controllers\Api\Portal\PlanController;
use App\Http\Controllers\Api\Portal\PortalAuthController;
use App\Http\Controllers\Api\Portal\ReportController;
use App\Http\Controllers\Api\Portal\SettingController;
use App\Http\Controllers\Api\Portal\SubscriptionController;
use App\Http\Controllers\Api\Portal\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal context (KIYU platform administration)
|--------------------------------------------------------------------------
|
| PlatformAdmin only, with `tenant_id = NULL`. There is no register endpoint:
| platform accounts are provisioned internally. This context never executes
| tenant operational controllers and has no tenant impersonation.
|
*/

Route::prefix('auth')->controller(PortalAuthController::class)->group(function () {
    Route::post('/login', 'login')->middleware('throttle:6,1');
});

Route::middleware(['auth:sanctum', 'context:portal', 'role:platform_admin'])->group(function () {
    Route::prefix('auth')->controller(PortalAuthController::class)->group(function () {
        Route::get('/me', 'me');
        Route::post('/logout', 'logout');
    });

    Route::get('/dashboard', DashboardController::class);

    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::get('/tenants/{id}', [TenantController::class, 'show'])->whereNumber('id');
    Route::post('/tenants/{id}/suspend', [TenantController::class, 'suspend'])->whereNumber('id');
    Route::post('/tenants/{id}/reactivate', [TenantController::class, 'reactivate'])->whereNumber('id');

    Route::get('/subscriptions', [SubscriptionController::class, 'index']);
    Route::get('/subscriptions/{id}', [SubscriptionController::class, 'show'])->whereNumber('id');

    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{id}', [PaymentController::class, 'show'])->whereNumber('id');
    Route::get('/payments-webhooks', [PaymentController::class, 'webhooks']);

    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::get('/plans/{id}', [PlanController::class, 'show'])->whereNumber('id');
    Route::put('/plans/{id}', [PlanController::class, 'update'])->whereNumber('id');
    Route::post('/plans/{id}/prices', [PlanController::class, 'addPrice'])->whereNumber('id');
    Route::put('/plans/{id}/entitlements', [PlanController::class, 'updateEntitlements'])->whereNumber('id');

    Route::get('/reports/revenue', [ReportController::class, 'revenue']);
    Route::get('/reports/growth', [ReportController::class, 'growth']);
    Route::get('/reports/subscriptions', [ReportController::class, 'subscriptions']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/settings', [SettingController::class, 'show']);
    Route::put('/settings', [SettingController::class, 'update']);
});
