
<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Billing\PaymentController;
use App\Http\Controllers\Api\Billing\SubscriptionController;
use App\Http\Controllers\Api\Billing\WebhookController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClinicalQueueController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DisplayDeviceController;
use App\Http\Controllers\Api\DisplayPairingController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\IntakeController;
use App\Http\Controllers\Api\KioskDeviceController;
use App\Http\Controllers\Api\KioskPairingController;
use App\Http\Controllers\Api\Platform\PlanController;
use App\Http\Controllers\Api\Platform\TenantController;
use App\Http\Controllers\Api\PublicQueueController;
use App\Http\Controllers\Api\QueueAcquisitionController;
use App\Http\Controllers\Api\QueueMonitorController;
use App\Http\Controllers\Api\ReceptionController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VisitController;
use App\Http\Controllers\Api\WhatsAppController;
use App\Http\Controllers\Api\WorkflowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Public authentication endpoints and authenticated session management.
|
*/

Route::prefix('auth')->controller(AuthController::class)->group(function () {
    // Public authentication
    Route::post('/login', 'login')->middleware('throttle:6,1');
    Route::post('/register', 'register')->middleware(['throttle:6,1', 'public-tenant']);

    // Authenticated session
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', 'me');
        Route::post('/logout', 'logout');
    });
});

/*
|--------------------------------------------------------------------------
| Public Endpoints
|--------------------------------------------------------------------------
|
| Endpoints accessible without authentication.
| Only expose information and operations intended for public access.
|
*/

// Public intake: kiosk and online registration
Route::prefix('intake')->controller(IntakeController::class)->group(function () {
    // Kiosk operations resolve tenant and facility from a registered device
    // credential, never from the request body.
    Route::post('/kiosk', 'kiosk')->middleware('kiosk');
    Route::get('/online-lookup/{identifier}', 'onlineLookup')->middleware('kiosk');
    Route::post('/online-checkin', 'onlineCheckin')->middleware('kiosk');

    // Online booking is reached from the client-facing tenant site and is
    // resolved by the public tenant locator.
    Route::post('/online', 'online')->middleware('public-tenant');
});

// Kiosk device onboarding. A device redeems the short pairing code an admin
// generated and receives its long-lived credential. Rate limited because the
// code is short; the code is single-use and expires.
Route::post('/kiosk/pair', [KioskPairingController::class, 'pair'])
    ->middleware('throttle:10,1');

// Display device onboarding: same redeem-a-code flow as the kiosk.
Route::post('/display/pair', [DisplayPairingController::class, 'pair'])
    ->middleware('throttle:10,1');

// Public queue display (without personally identifiable information)
Route::get('/public/queue', [PublicQueueController::class, 'index'])
    ->middleware(['public-tenant', 'throttle:120,1']);

// Public organizational lookup for kiosk
Route::get('/departments/active', [DepartmentController::class, 'active'])
    ->middleware(['public-tenant', 'throttle:120,1']);

// Public visit tracking
Route::get('/visits/{token}/track', [VisitController::class, 'track']);

// Payment provider webhook. Public (no auth/tenant): the payload is untrusted
// until PaymentService verifies its signature; replays are idempotent.
Route::post('/webhooks/payments/{provider}', [WebhookController::class, 'handle']);

/*
|--------------------------------------------------------------------------
| Authenticated Endpoints
|--------------------------------------------------------------------------
|
| All endpoints below require a valid Laravel Sanctum token.
|
*/

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Intake Management
    |--------------------------------------------------------------------------
    |
    | Manual client registration and queue acquisition management.
    |
    */

    // Manual registration (reception)
    Route::post('/intake/manual', [IntakeController::class, 'manual'])->middleware('role:admin,receptionist');

    // Queue acquisition records
    Route::prefix('queue-acquisitions')->middleware('role:admin,receptionist')->controller(QueueAcquisitionController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::post('/{id}/cancel', 'cancel')->whereNumber('id');
    });

    /*
    |--------------------------------------------------------------------------
    | Reception Operations
    |--------------------------------------------------------------------------
    |
    | Registration queue management and reception ticket operations.
    |
    */

    Route::prefix('reception')->middleware('role:admin,receptionist')->controller(ReceptionController::class)->group(function () {
        Route::get('/queue', 'queue');

        Route::post('/call-next', 'callNext');
        Route::post('/complete-registration', 'completeRegistration');

        Route::post('/{ticketId}/recall', 'recall')->whereNumber('ticketId');
        Route::post('/{ticketId}/skip', 'skip')->whereNumber('ticketId');
        Route::post('/{ticketId}/no-show', 'noShow')->whereNumber('ticketId');
    });

    /*
    |--------------------------------------------------------------------------
    | Clinical Queue Operations
    |--------------------------------------------------------------------------
    |
    | Clinical queue processing and ticket lifecycle management.
    |
    */

    // Clinical queue
    Route::post('/clinical/call-next', [ClinicalQueueController::class, 'callNext'])
        ->middleware('role:admin,receptionist,staff');

    // Ticket records and lifecycle operations
    Route::prefix('tickets')->middleware('role:admin,receptionist,staff')->group(function () {
        // Ticket queries
        Route::get('/', [TicketController::class, 'index']);
        Route::get('/statistics', [TicketController::class, 'statistics']);
        Route::get('/{id}', [TicketController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/events', [TicketController::class, 'events'])->whereNumber('id');

        // Ticket lifecycle
        Route::controller(ClinicalQueueController::class)->group(function () {
            Route::post('/{id}/start', 'start')->whereNumber('id');
            Route::post('/{id}/hold', 'hold')->whereNumber('id');
            Route::post('/{id}/resume', 'resume')->whereNumber('id');
            Route::post('/{id}/recall', 'recall')->whereNumber('id');
            Route::post('/{id}/complete', 'complete')->whereNumber('id');
            Route::post('/{id}/skip', 'skip')->whereNumber('id');
            Route::post('/{id}/no-show', 'noShow')->whereNumber('id');
            Route::post('/{id}/cancel', 'cancel')->whereNumber('id');
            Route::post('/{id}/transfer', 'transfer')->whereNumber('id');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Referral Management
    |--------------------------------------------------------------------------
    |
    | Client referral records.
    |
    */

    Route::prefix('referrals')->middleware('role:admin,staff')->controller(ReferralController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{id}', 'show')->whereNumber('id');
    });

    /*
    |--------------------------------------------------------------------------
    | Visit Management
    |--------------------------------------------------------------------------
    |
    | Client visits, visit statistics, priority updates and cancellation.
    |
    */

    Route::prefix('visits')->controller(VisitController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/statistics', 'statistics');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::get('/{id}/acquisition', 'acquisition')->whereNumber('id');

        Route::patch('/{id}/priority', 'updatePriority')->whereNumber('id')->middleware('role:admin,receptionist,staff');
        Route::post('/{id}/cancel', 'cancel')->whereNumber('id')->middleware('role:admin,receptionist,staff');
    });

    /*
    |--------------------------------------------------------------------------
    | Client Management
    |--------------------------------------------------------------------------
    |
    | Client directory, client records and visit history.
    |
    */

    Route::prefix('clients')->controller(ClientController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/statistics', 'statistics');
        Route::post('/', 'store');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::put('/{id}', 'update')->whereNumber('id');
        Route::get('/{id}/visits', 'visits')->whereNumber('id');
    });

    /*
    |--------------------------------------------------------------------------
    | Organizational Management
    |--------------------------------------------------------------------------
    |
    | Department, station and workflow configuration.
    |
    */

    // Departments
    Route::prefix('departments')->controller(DepartmentController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/options', 'options');
        Route::post('/', 'store')->middleware('role:admin');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::put('/{id}', 'update')->whereNumber('id')->middleware('role:admin');

        Route::get('/{id}/stations', 'stations')->whereNumber('id');
        Route::get('/{id}/workflows', 'workflows')->whereNumber('id');
    });

    // Sanctum bearer tokens; this returns the same read model per tenant.
    Route::get('/queue/monitor', [QueueMonitorController::class, 'index'])
        ->middleware('role:admin,receptionist,staff');

    // Stations
    Route::prefix('stations')->controller(StationController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/options', 'options');
        Route::post('/', 'store')->middleware('role:admin');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::put('/{id}', 'update')->whereNumber('id')->middleware('role:admin');

        Route::get('/{id}/queue', 'queue')->whereNumber('id')->middleware('role:admin,receptionist,staff');
    });

    // Workflows
    Route::prefix('workflows')->controller(WorkflowController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/statistics', 'statistics');
        Route::post('/', 'store')->middleware('role:admin');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::post('/{id}/steps', 'addStep')->whereNumber('id')->middleware('role:admin');
    });

    // Workflow steps
    Route::prefix('workflow-steps')->controller(WorkflowController::class)->group(function () {
        Route::put('/{id}', 'updateStep')->whereNumber('id')->middleware('role:admin');
        Route::delete('/{id}', 'deleteStep')->whereNumber('id')->middleware('role:admin');
    });

    /*
    |--------------------------------------------------------------------------
    | User Management
    |--------------------------------------------------------------------------
    |
    | User account administration.
    | Administrative authorization is enforced by UserController.
    |
    */

    Route::prefix('users')->middleware('role:admin')->controller(UserController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::put('/{id}', 'update')->whereNumber('id');
        Route::delete('/{id}', 'destroy')->whereNumber('id');
    });

    /*
    |--------------------------------------------------------------------------
    | Audit Logs
    |--------------------------------------------------------------------------
    |
    | System activity and audit trail records.
    |
    */

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('role:admin');

    /*
    |--------------------------------------------------------------------------
    | Billing (tenant self-service)
    |--------------------------------------------------------------------------
    |
    | The tenant's own subscription. Admins manage it; other roles only read.
    |
    */

    Route::prefix('billing/subscription')->controller(SubscriptionController::class)->group(function () {
        Route::get('/', 'show');
        Route::post('/', 'store')->middleware('role:admin');
        Route::post('/cancel', 'cancel')->middleware('role:admin');
    });

    // Start a payment charge for the tenant's subscription.
    Route::post('/billing/pay', [PaymentController::class, 'charge'])->middleware('role:admin');

    /*
    |--------------------------------------------------------------------------
    | Organisation Resources (limit-gated)
    |--------------------------------------------------------------------------
    |
    | Creating a facility or kiosk device is capped by the plan's max_* limit.
    |
    */

    Route::prefix('facilities')->controller(FacilityController::class)->group(function () {
        Route::get('/', 'index')->middleware('role:admin');
        Route::post('/', 'store')->middleware('role:admin');
        Route::put('/{id}', 'update')->whereNumber('id')->middleware('role:admin');
    });

    Route::prefix('kiosk-devices')->controller(KioskDeviceController::class)->group(function () {
        Route::get('/', 'index')->middleware('role:admin');
        Route::post('/', 'store')->middleware('role:admin');
        Route::delete('/{id}', 'destroy')->whereNumber('id')->middleware('role:admin');
        Route::post('/{id}/pairing-code', 'reissuePairingCode')->whereNumber('id')->middleware('role:admin');
    });

    Route::prefix('display-devices')->controller(DisplayDeviceController::class)->group(function () {
        Route::get('/', 'index')->middleware('role:admin');
        Route::post('/', 'store')->middleware('role:admin');
        Route::delete('/{id}', 'destroy')->whereNumber('id')->middleware('role:admin');
        Route::post('/{id}/pairing-code', 'reissuePairingCode')->whereNumber('id')->middleware('role:admin');
    });

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    |
    | JSON summaries need basic_reports; the CSV export is an advanced feature.
    |
    */

    Route::prefix('reports')->controller(ReportController::class)->group(function () {
        Route::get('/summary', 'summary')
            ->middleware(['role:admin,receptionist', 'feature:basic_reports']);
        Route::get('/visits/export', 'exportVisits')
            ->middleware(['role:admin', 'feature:advanced_reports']);
        Route::post('/visits/export-file', 'exportFile')
            ->middleware(['role:admin', 'feature:advanced_reports']);
        Route::get('/download', 'download')
            ->name('reports.download')
            ->middleware(['role:admin', 'feature:advanced_reports']);
    });

    /*
    |--------------------------------------------------------------------------
    | WhatsApp notifications
    |--------------------------------------------------------------------------
    |
    | Provider-neutral gateway; requires the whatsapp_notifications feature.
    |
    */

    Route::post('/whatsapp/messages', [WhatsAppController::class, 'send'])
        ->middleware(['role:admin,receptionist', 'feature:whatsapp_notifications']);
});

/*
|--------------------------------------------------------------------------
| Platform Administration
|--------------------------------------------------------------------------
|
| Platform-only routes. These never run inside a tenant context and are
| reachable only by an account explicitly typed PLATFORM.
|
*/

Route::prefix('platform')->middleware(['auth:sanctum', 'platform'])->group(function () {
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::get('/tenants/{id}', [TenantController::class, 'show'])->whereNumber('id');

    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);

    /*
    |--------------------------------------------------------------------------
    | Tenant support: act as a clinic admin on a chosen tenant
    |--------------------------------------------------------------------------
    |
    | `platform-tenant` binds the target tenant from {tenant}, then the normal
    | tenant-scoped controllers run against that tenant. Feature and limit
    | checks use the target tenant's subscription, so support never bypasses
    | plan enforcement.
    |
    */

    Route::prefix('/tenants/{tenant}/manage')->middleware('platform-tenant')->group(function () {
        // Staff users
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
        Route::put('/users/{id}', [UserController::class, 'update'])->whereNumber('id');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereNumber('id');

        // Client records
        Route::get('/clients', [ClientController::class, 'index']);
        Route::get('/clients/{id}', [ClientController::class, 'show'])->whereNumber('id');
        Route::put('/clients/{id}', [ClientController::class, 'update'])->whereNumber('id');
        Route::get('/clients/{id}/visits', [ClientController::class, 'visits'])->whereNumber('id');

        // Facilities and kiosk devices
        Route::get('/facilities', [FacilityController::class, 'index']);
        Route::post('/facilities', [FacilityController::class, 'store']);
        Route::put('/facilities/{id}', [FacilityController::class, 'update'])->whereNumber('id');
        Route::get('/kiosk-devices', [KioskDeviceController::class, 'index']);
        Route::post('/kiosk-devices', [KioskDeviceController::class, 'store']);
        Route::delete('/kiosk-devices/{id}', [KioskDeviceController::class, 'destroy'])->whereNumber('id');
        Route::post('/kiosk-devices/{id}/pairing-code', [KioskDeviceController::class, 'reissuePairingCode'])->whereNumber('id');

        // Visits (read + staff actions)
        Route::get('/visits', [VisitController::class, 'index']);
        Route::get('/visits/{id}', [VisitController::class, 'show'])->whereNumber('id');
        Route::post('/visits/{id}/cancel', [VisitController::class, 'cancel'])->whereNumber('id');
        Route::patch('/visits/{id}/priority', [VisitController::class, 'updatePriority'])->whereNumber('id');

        // Audit trail for the tenant
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });
});
