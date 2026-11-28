<?php

use App\Http\Controllers\Api\App\AppAuthController;
use App\Http\Controllers\Api\App\AuditLogController;
use App\Http\Controllers\Api\App\Billing\PaymentController;
use App\Http\Controllers\Api\App\Billing\SubscriptionController;
use App\Http\Controllers\Api\App\Configuration\DepartmentController;
use App\Http\Controllers\Api\App\Configuration\StationController;
use App\Http\Controllers\Api\App\Configuration\WorkflowController;
use App\Http\Controllers\Api\App\DashboardController;
use App\Http\Controllers\Api\App\Facilities\FacilityController;
use App\Http\Controllers\Api\App\Monitoring\CustomerController as MonitoringCustomerController;
use App\Http\Controllers\Api\App\Monitoring\QueueMonitorController;
use App\Http\Controllers\Api\App\Monitoring\QueueSessionController;
use App\Http\Controllers\Api\App\Onboarding\SignupController;
use App\Http\Controllers\Api\App\Reports\ReportController;
use App\Http\Controllers\Api\App\Users\FacilityAssignmentController;
use App\Http\Controllers\Api\App\Users\InvitationController;
use App\Http\Controllers\Api\App\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| App context (customer SaaS, tenant_admin only)
|--------------------------------------------------------------------------
|
| Signup/login are guest endpoints but still App-origin-bound. Every
| authenticated endpoint requires a `ctx:app` token, the tenant_admin role, and
| resolves the tenant from the authenticated account.
|
*/

Route::prefix('auth')->group(function () {
    Route::post('/signup', [SignupController::class, 'register'])->middleware('throttle:3,60');

    Route::controller(AppAuthController::class)->group(function () {
        Route::post('/login', 'login')->middleware('throttle:6,1');
    });
});

Route::middleware(['auth:sanctum', 'context:app', 'role:tenant_admin', 'app.tenant'])->group(function () {
    Route::prefix('auth')->controller(AppAuthController::class)->group(function () {
        Route::get('/me', 'me');
        Route::post('/logout', 'logout');
    });

    Route::get('/dashboard', DashboardController::class);

    // Facilities.
    Route::prefix('facilities')->controller(FacilityController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::put('/{id}', 'update')->whereNumber('id');
    });

    // Users + facility assignments + invitations.
    Route::prefix('users')->controller(UserController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::put('/{id}', 'update')->whereNumber('id');
        Route::delete('/{id}', 'destroy')->whereNumber('id');
    });

    Route::prefix('users/{userId}/facilities')
        ->controller(FacilityAssignmentController::class)
        ->group(function () {
            Route::get('/', 'index')->whereNumber('userId');
            Route::post('/', 'store')->whereNumber('userId');
            Route::delete('/{facilityId}', 'destroy')->whereNumber('userId')->whereNumber('facilityId');
        });

    Route::prefix('invitations')->controller(InvitationController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::delete('/{id}', 'revoke')->whereNumber('id');
    });

    // Facility-scoped configuration (X-Facility-Id selects the facility).
    Route::middleware('facility')->group(function () {
        Route::prefix('departments')->controller(DepartmentController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/options', 'options');
            Route::post('/', 'store');
            Route::get('/{id}', 'show')->whereNumber('id');
            Route::put('/{id}', 'update')->whereNumber('id');
            Route::get('/{id}/stations', 'stations')->whereNumber('id');
            Route::get('/{id}/workflows', 'workflows')->whereNumber('id');
        });

        Route::prefix('stations')->controller(StationController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/options', 'options');
            Route::post('/', 'store');
            Route::get('/{id}', 'show')->whereNumber('id');
            Route::put('/{id}', 'update')->whereNumber('id');
            Route::get('/{id}/queue', 'queue')->whereNumber('id');
        });

        Route::prefix('workflows')->controller(WorkflowController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/statistics', 'statistics');
            Route::post('/', 'store');
            Route::get('/{id}', 'show')->whereNumber('id');
            Route::post('/{id}/steps', 'addStep')->whereNumber('id');
        });

        Route::prefix('workflow-steps')->controller(WorkflowController::class)->group(function () {
            Route::put('/{id}', 'updateStep')->whereNumber('id');
            Route::delete('/{id}', 'deleteStep')->whereNumber('id');
        });

        Route::get('/reports/summary', [ReportController::class, 'summary'])
            ->middleware('feature:report_queue_overview');
        Route::get('/reports/queue-sessions/export', [ReportController::class, 'exportQueueSessions'])
            ->middleware(['feature:report_queue_overview', 'feature:report_export']);
        Route::post('/reports/queue-sessions/export-file', [ReportController::class, 'exportFile'])
            ->middleware(['feature:report_queue_overview', 'feature:report_export']);
        Route::get('/reports/download', [ReportController::class, 'download'])
            ->name('app.reports.download')
            ->middleware(['feature:report_queue_overview', 'feature:report_export']);
    });

    // Organization-wide monitoring (tenant scope, facility filter).
    Route::get('/queue/monitor', [QueueMonitorController::class, 'index']);
    Route::get('/queue-sessions', [QueueSessionController::class, 'index']);
    Route::get('/queue-sessions/statistics', [QueueSessionController::class, 'statistics']);
    Route::get('/queue-sessions/{id}', [QueueSessionController::class, 'show'])->whereNumber('id');
    Route::post('/queue-sessions/{id}/cancel', [QueueSessionController::class, 'cancel'])->whereNumber('id');

    Route::get('/customers', [MonitoringCustomerController::class, 'index']);
    Route::get('/customers/statistics', [MonitoringCustomerController::class, 'statistics']);
    Route::get('/customers/{id}', [MonitoringCustomerController::class, 'show'])->whereNumber('id');
    Route::get('/customers/{id}/queue-sessions', [MonitoringCustomerController::class, 'queueSessions'])->whereNumber('id');

    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    // Billing (tenant self-service).
    Route::prefix('billing/subscription')->controller(SubscriptionController::class)->group(function () {
        Route::get('/', 'show');
        Route::post('/', 'store');
        Route::post('/cancel', 'cancel');
    });
    Route::get('/billing/entitlements', [SubscriptionController::class, 'entitlements']);
    Route::get('/billing/plans', [SubscriptionController::class, 'plans']);
    Route::post('/billing/pay', [PaymentController::class, 'charge']);
});
