<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Billing\PaymentController;
use App\Http\Controllers\Api\Billing\SubscriptionController;
use App\Http\Controllers\Api\Billing\WebhookController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DisplayDeviceController;
use App\Http\Controllers\Api\DisplayPairingController;
use App\Http\Controllers\Api\FacilityAssignmentController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\IntakeController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\KioskDeviceController;
use App\Http\Controllers\Api\KioskPairingController;
use App\Http\Controllers\Api\Platform\PlanController;
use App\Http\Controllers\Api\Platform\TenantController;
use App\Http\Controllers\Api\PublicQueueController;
use App\Http\Controllers\Api\QueueAcquisitionController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\QueueMonitorController;
use App\Http\Controllers\Api\QueueSessionController;
use App\Http\Controllers\Api\ReceptionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ServiceTransferController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WhatsAppController;
use App\Http\Controllers\Api\WorkflowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('auth')
    ->controller(AuthController::class)
    ->group(function () {
        Route::post('/login', 'login')->middleware('throttle:6,1');

        // Tenant user self-registration. The tenant comes from the tenant host or
        // the X-Tenant-Slug locator; the tenant's own registration policy decides
        // whether it is open, and the endpoint can only ever create a client.
        Route::post('/register', 'register')
            ->middleware(['throttle:6,1', 'public-tenant']);

        Route::post('/register/invitation', [InvitationController::class, 'accept'])
            ->middleware('throttle:6,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', 'me');
            Route::post('/logout', 'logout');
        });
    });

/*
|--------------------------------------------------------------------------
| Public Endpoints
|--------------------------------------------------------------------------
*/

Route::prefix('intake')
    ->controller(IntakeController::class)
    ->group(function () {
        Route::post('/kiosk', 'kiosk')
            ->middleware(['kiosk', 'throttle:30,1']);
        Route::get('/online-lookup/{identifier}', 'onlineLookup')
            ->middleware(['kiosk', 'throttle:60,1']);
        Route::post('/online-checkin', 'onlineCheckin')
            ->middleware(['kiosk', 'throttle:30,1']);
        Route::post('/online', 'online')
            ->middleware(['auth:sanctum', 'tenant', 'throttle:10,1']);
    });

Route::post('/kiosk/pair', [KioskPairingController::class, 'pair'])
    ->middleware('throttle:10,1');

Route::post('/display/pair', [DisplayPairingController::class, 'pair'])
    ->middleware('throttle:10,1');

Route::get('/public/queue', [PublicQueueController::class, 'index'])
    ->middleware(['public-tenant', 'throttle:120,1']);

Route::get('/departments/active', [DepartmentController::class, 'active'])
    ->middleware(['public-tenant', 'throttle:120,1']);

Route::get('/queue-sessions/{token}/track', [QueueSessionController::class, 'track'])
    ->middleware('throttle:60,1');

Route::post('/webhooks/payments/{provider}', [WebhookController::class, 'handle'])
    ->middleware('throttle:120,1');

/*
|--------------------------------------------------------------------------
| Tenant Endpoints
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'tenant'])
    ->group(function () {

        Route::post('/intake/manual', [IntakeController::class, 'manual'])
            ->middleware(['facility', 'role:tenant_admin,facility_admin,operator']);

        Route::prefix('queue-acquisitions')
            ->middleware('role:tenant_admin,facility_admin,operator')
            ->controller(QueueAcquisitionController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::get('/{id}', 'show')->whereNumber('id');
                Route::post('/{id}/cancel', 'cancel')->whereNumber('id');
            });

        Route::prefix('reception')
            ->middleware(['facility', 'role:tenant_admin,facility_admin,operator'])
            ->controller(ReceptionController::class)
            ->group(function () {
                Route::get('/queue', 'queue');
                Route::post('/call-next', 'callNext');
                Route::post('/complete-registration', 'completeRegistration');
                Route::post('/{ticketId}/recall', 'recall')->whereNumber('ticketId');
                Route::post('/{ticketId}/skip', 'skip')->whereNumber('ticketId');
                Route::post('/{ticketId}/no-show', 'noShow')->whereNumber('ticketId');
            });

        /*
        |--------------------------------------------------------------------------
        | Queue Processing
        |--------------------------------------------------------------------------
        */

        Route::post('/queue/call-next', [QueueController::class, 'callNext'])
            ->middleware(['facility', 'role:tenant_admin,facility_admin,operator,staff']);

        Route::prefix('tickets')
            ->middleware(['facility', 'role:tenant_admin,facility_admin,operator,staff'])
            ->group(function () {
                Route::get('/', [TicketController::class, 'index']);
                Route::get('/statistics', [TicketController::class, 'statistics']);
                Route::get('/{id}', [TicketController::class, 'show'])->whereNumber('id');
                Route::get('/{id}/events', [TicketController::class, 'events'])->whereNumber('id');

                Route::controller(QueueController::class)->group(function () {
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

        Route::prefix('service-transfers')
            ->middleware('role:tenant_admin,facility_admin,staff')
            ->controller(ServiceTransferController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{id}', 'show')->whereNumber('id');
            });

        /*
        |--------------------------------------------------------------------------
        | Queue Sessions
        |--------------------------------------------------------------------------
        */

        Route::prefix('queue-sessions')
            ->controller(QueueSessionController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::get('/statistics', 'statistics');
                Route::get('/{id}', 'show')->whereNumber('id');
                Route::get('/{id}/acquisition', 'acquisition')->whereNumber('id');

                Route::patch('/{id}/priority', 'updatePriority')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');

                Route::post('/{id}/cancel', 'cancel')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');
            });

        /*
        |--------------------------------------------------------------------------
        | Customers
        |--------------------------------------------------------------------------
        */

        Route::prefix('customers')
            ->controller(CustomerController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::get('/statistics', 'statistics');
                Route::post('/', 'store');
                Route::get('/{id}', 'show')->whereNumber('id');
                Route::put('/{id}', 'update')->whereNumber('id');
                Route::get('/{id}/queue-sessions', 'queueSessions')->whereNumber('id');
            });

        /*
        |--------------------------------------------------------------------------
        | Organisational Configuration
        |--------------------------------------------------------------------------
        */

        Route::prefix('departments')
            ->controller(DepartmentController::class)
            ->middleware('facility')
            ->group(function () {
                Route::get('/', 'index')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');

                Route::get('/options', 'options')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');

                Route::post('/', 'store')
                    ->middleware('role:tenant_admin,facility_admin');

                Route::get('/{id}', 'show')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');

                Route::put('/{id}', 'update')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin');

                Route::get('/{id}/stations', 'stations')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');

                Route::get('/{id}/workflows', 'workflows')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');
            });

        Route::get('/queue/monitor', [QueueMonitorController::class, 'index'])
            ->middleware(['facility', 'role:tenant_admin,facility_admin,operator,staff']);

        Route::prefix('stations')
            ->controller(StationController::class)
            ->middleware('facility')
            ->group(function () {
                Route::get('/', 'index')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');

                Route::get('/options', 'options')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');

                Route::post('/', 'store')
                    ->middleware('role:tenant_admin,facility_admin');

                Route::get('/{id}', 'show')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');

                Route::put('/{id}', 'update')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin');

                Route::get('/{id}/queue', 'queue')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');
            });

        Route::prefix('workflows')
            ->controller(WorkflowController::class)
            ->middleware('facility')
            ->group(function () {
                Route::get('/', 'index')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');

                Route::get('/statistics', 'statistics')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');

                Route::post('/', 'store')
                    ->middleware('role:tenant_admin,facility_admin');

                Route::get('/{id}', 'show')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin,operator,staff');

                Route::post('/{id}/steps', 'addStep')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin');

            });

        Route::prefix('workflow-steps')
            ->controller(WorkflowController::class)
            ->group(function () {
                Route::put('/{id}', 'updateStep')
                    ->whereNumber('id')
                    ->middleware(['facility', 'role:tenant_admin,facility_admin']);
                Route::delete('/{id}', 'deleteStep')
                    ->whereNumber('id')
                    ->middleware(['facility', 'role:tenant_admin,facility_admin']);
            });

        /*
        |--------------------------------------------------------------------------
        | User Management
        |--------------------------------------------------------------------------
        */

        Route::prefix('users')
            ->middleware('role:tenant_admin,facility_admin')
            ->controller(UserController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{id}', 'show')->whereNumber('id');
                Route::put('/{id}', 'update')->whereNumber('id');
                Route::delete('/{id}', 'destroy')->whereNumber('id');
            });

        // Facility assignments for a tenant user.
        Route::prefix('users/{userId}/facilities')
            ->middleware('role:tenant_admin,facility_admin')
            ->controller(FacilityAssignmentController::class)
            ->group(function () {
                Route::get('/', 'index')->whereNumber('userId');
                Route::post('/', 'store')->whereNumber('userId');
                Route::delete('/{facilityId}', 'destroy')->whereNumber('userId')->whereNumber('facilityId');
            });

        // Invitations into the tenant.
        Route::prefix('invitations')
            ->middleware('role:tenant_admin,facility_admin')
            ->controller(InvitationController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::delete('/{id}', 'revoke')->whereNumber('id');
            });

        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->middleware('role:tenant_admin');

        /*
        |--------------------------------------------------------------------------
        | Billing (tenant self-service)
        |--------------------------------------------------------------------------
        */

        Route::prefix('billing/subscription')
            ->controller(SubscriptionController::class)
            ->group(function () {
                Route::get('/', 'show')->middleware('role:tenant_admin');
                Route::post('/', 'store')->middleware('role:tenant_admin');
                Route::post('/cancel', 'cancel')->middleware('role:tenant_admin');
            });

        Route::post('/billing/pay', [PaymentController::class, 'charge'])
            ->middleware('role:tenant_admin');

        /*
        |--------------------------------------------------------------------------
        | Organisation Resources (limit-gated)
        |--------------------------------------------------------------------------
        */

        Route::prefix('facilities')
            ->controller(FacilityController::class)
            ->group(function () {
                Route::get('/', 'index')
                    ->middleware('role:tenant_admin,facility_admin');
                Route::post('/', 'store')
                    ->middleware('role:tenant_admin');
                Route::put('/{id}', 'update')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin');
            });

        Route::prefix('kiosk-devices')
            ->controller(KioskDeviceController::class)
            ->group(function () {
                // Listing is tenant-wide (the quota is per tenant), but a device
                // always belongs to one facility, so creation needs a context.
                Route::get('/', 'index')
                    ->middleware('role:tenant_admin,facility_admin');
                Route::post('/', 'store')
                    ->middleware('role:tenant_admin,facility_admin');
                Route::delete('/{id}', 'destroy')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin');
                Route::post('/{id}/pairing-code', 'reissuePairingCode')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin');
            });

        Route::prefix('display-devices')
            ->controller(DisplayDeviceController::class)
            ->group(function () {
                Route::get('/', 'index')
                    ->middleware('role:tenant_admin,facility_admin');
                Route::post('/', 'store')
                    ->middleware('role:tenant_admin,facility_admin');
                Route::delete('/{id}', 'destroy')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin');
                Route::post('/{id}/pairing-code', 'reissuePairingCode')->whereNumber('id')
                    ->middleware('role:tenant_admin,facility_admin');
            });

        /*
        |--------------------------------------------------------------------------
        | Reports
        |--------------------------------------------------------------------------
        */

        Route::prefix('reports')
            ->controller(ReportController::class)
            ->middleware('facility')
            ->group(function () {
                Route::get('/summary', 'summary')
                    ->middleware(['role:tenant_admin,operator', 'feature:basic_reports']);
                Route::get('/queue-sessions/export', 'exportQueueSessions')
                    ->middleware(['role:tenant_admin', 'feature:advanced_reports']);
                Route::post('/queue-sessions/export-file', 'exportFile')
                    ->middleware(['role:tenant_admin', 'feature:advanced_reports']);
                Route::get('/download', 'download')
                    ->name('reports.download')
                    ->middleware(['role:tenant_admin', 'feature:advanced_reports']);
            });

        Route::post('/whatsapp/messages', [WhatsAppController::class, 'send'])
            ->middleware(['role:tenant_admin,operator', 'feature:whatsapp_notifications']);
    });

/*
|--------------------------------------------------------------------------
| Platform Administration
|--------------------------------------------------------------------------
*/

Route::prefix('platform')
    ->middleware(['auth:sanctum', 'platform'])
    ->group(function () {
        Route::get('/tenants', [TenantController::class, 'index']);
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::get('/tenants/{id}', [TenantController::class, 'show'])->whereNumber('id');
        Route::get('/plans', [PlanController::class, 'index']);
        Route::post('/plans', [PlanController::class, 'store']);

        /*
        |--------------------------------------------------------------------------
        | Tenant support: act as a tenant admin on a chosen tenant
        |--------------------------------------------------------------------------
        |
        | `platform-tenant` binds the target tenant from {tenant}, then the normal
        | tenant-scoped controllers run against that tenant. Feature and limit
        | checks use the target tenant's subscription, so support never bypasses
        | plan enforcement. Every support action is audited.
        |
        */

        Route::prefix('/tenants/{tenant}/manage')
            ->middleware('platform-tenant')
            ->group(function () {
                Route::get('/users', [UserController::class, 'index']);
                Route::post('/users', [UserController::class, 'store']);
                Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
                Route::put('/users/{id}', [UserController::class, 'update'])->whereNumber('id');
                Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereNumber('id');

                Route::get('/customers', [CustomerController::class, 'index']);
                Route::get('/customers/{id}', [CustomerController::class, 'show'])->whereNumber('id');
                Route::put('/customers/{id}', [CustomerController::class, 'update'])->whereNumber('id');
                Route::get('/customers/{id}/queue-sessions', [CustomerController::class, 'queueSessions'])->whereNumber('id');

                Route::get('/facilities', [FacilityController::class, 'index']);
                Route::post('/facilities', [FacilityController::class, 'store']);
                Route::put('/facilities/{id}', [FacilityController::class, 'update'])->whereNumber('id');
                Route::get('/kiosk-devices', [KioskDeviceController::class, 'index']);
                Route::post('/kiosk-devices', [KioskDeviceController::class, 'store']);
                Route::delete('/kiosk-devices/{id}', [KioskDeviceController::class, 'destroy'])->whereNumber('id');
                Route::post('/kiosk-devices/{id}/pairing-code', [KioskDeviceController::class, 'reissuePairingCode'])->whereNumber('id');

                Route::get('/queue-sessions', [QueueSessionController::class, 'index']);
                Route::get('/queue-sessions/{id}', [QueueSessionController::class, 'show'])->whereNumber('id');
                Route::post('/queue-sessions/{id}/cancel', [QueueSessionController::class, 'cancel'])->whereNumber('id');
                Route::patch('/queue-sessions/{id}/priority', [QueueSessionController::class, 'updatePriority'])->whereNumber('id');

                Route::get('/audit-logs', [AuditLogController::class, 'index']);
            });
    });
