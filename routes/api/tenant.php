<?php

use App\Http\Controllers\Api\Tenant\Configuration\DepartmentController;
use App\Http\Controllers\Api\Tenant\Configuration\StationController;
use App\Http\Controllers\Api\Tenant\Configuration\WorkflowController;
use App\Http\Controllers\Api\Tenant\Customers\CustomerController;
use App\Http\Controllers\Api\Tenant\Devices\DisplayDeviceController;
use App\Http\Controllers\Api\Tenant\Devices\DisplayPairingController;
use App\Http\Controllers\Api\Tenant\Devices\KioskDeviceController;
use App\Http\Controllers\Api\Tenant\Devices\KioskPairingController;
use App\Http\Controllers\Api\Tenant\Intake\IntakeController;
use App\Http\Controllers\Api\Tenant\InvitationAcceptanceController;
use App\Http\Controllers\Api\Tenant\Notifications\WhatsAppController;
use App\Http\Controllers\Api\Tenant\Public\PublicQueueController;
use App\Http\Controllers\Api\Tenant\Public\TrackingController;
use App\Http\Controllers\Api\Tenant\Queue\QueueAcquisitionController;
use App\Http\Controllers\Api\Tenant\Queue\QueueController;
use App\Http\Controllers\Api\Tenant\Queue\QueueMonitorController;
use App\Http\Controllers\Api\Tenant\Queue\QueueSessionController;
use App\Http\Controllers\Api\Tenant\Queue\ServiceTransferController;
use App\Http\Controllers\Api\Tenant\Queue\TicketController;
use App\Http\Controllers\Api\Tenant\Reception\ReceptionController;
use App\Http\Controllers\Api\Tenant\Reports\ReportController;
use App\Http\Controllers\Api\Tenant\TenantAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant context (operational workspace)
|--------------------------------------------------------------------------
|
| Guest endpoints are tenant-origin-bound (validated Origin slug, scoped
| locator header, or a paired device credential). Authenticated endpoints
| require a `ctx:tenant` token, a facility_admin/operator/staff role, and the
| origin tenant slug must equal the authenticated user's tenant.
|
*/

// Guest: authentication.
Route::prefix('auth')->group(function () {
    Route::post('/login', [TenantAuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'accept'])
        ->middleware('throttle:6,1');
});

// Guest: tenant-origin-bound public runtime.
Route::middleware('tenant.origin')->group(function () {
    Route::get('/public/queue', [PublicQueueController::class, 'index'])->middleware('throttle:120,1');
    Route::get('/public/departments/active', [DepartmentController::class, 'active'])->middleware('throttle:120,1');
    Route::get('/public/tracking/{token}', [TrackingController::class, 'show'])->middleware('throttle:60,1');

    Route::post('/devices/kiosk/pair', [KioskPairingController::class, 'pair'])->middleware('throttle:10,1');
    Route::post('/devices/display/pair', [DisplayPairingController::class, 'pair'])->middleware('throttle:10,1');

    Route::prefix('intake')->controller(IntakeController::class)->group(function () {
        Route::post('/kiosk', 'kiosk')->middleware(['kiosk', 'throttle:30,1']);
        Route::get('/online-lookup/{identifier}', 'onlineLookup')->middleware(['kiosk', 'throttle:60,1']);
        Route::post('/online-checkin', 'onlineCheckin')->middleware(['kiosk', 'throttle:30,1']);
    });
});

// Authenticated workspace.
Route::middleware(['auth:sanctum', 'context:tenant', 'tenant.bind', 'tenant', 'role:facility_admin,operator,staff'])
    ->group(function () {

        Route::prefix('auth')->controller(TenantAuthController::class)->group(function () {
            Route::get('/me', 'me');
            Route::post('/logout', 'logout');
        });

        Route::post('/intake/manual', [IntakeController::class, 'manual'])
            ->middleware(['role:facility_admin,operator']);

        // Online booking on behalf of a customer (reception staff).
        Route::post('/intake/online', [IntakeController::class, 'online'])
            ->middleware(['role:facility_admin,operator']);

        Route::prefix('queue-acquisitions')
            ->middleware('role:facility_admin,operator')
            ->controller(QueueAcquisitionController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::get('/{id}', 'show')->whereNumber('id');
                Route::post('/{id}/cancel', 'cancel')->whereNumber('id');
            });

        Route::prefix('reception')
            ->middleware(['role:facility_admin,operator'])
            ->controller(ReceptionController::class)
            ->group(function () {
                Route::get('/queue', 'queue');
                Route::post('/call-next', 'callNext');
                Route::post('/complete-registration', 'completeRegistration');
                Route::post('/{ticketId}/recall', 'recall')->whereNumber('ticketId');
                Route::post('/{ticketId}/skip', 'skip')->whereNumber('ticketId');
                Route::post('/{ticketId}/no-show', 'noShow')->whereNumber('ticketId');
            });

        Route::post('/queue/call-next', [QueueController::class, 'callNext'])
            ->middleware(['role:facility_admin,operator,staff']);

        Route::prefix('tickets')
            ->middleware(['role:facility_admin,operator,staff'])
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

                    Route::post('/{id}/transfer', 'transfer')->whereNumber('id')
                        ->middleware('role:staff');
                });
            });

        Route::prefix('service-transfers')
            ->middleware('role:facility_admin,staff')
            ->controller(ServiceTransferController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::get('/{id}', 'show')->whereNumber('id');
                Route::post('/', 'store')->middleware('role:staff');
            });

        Route::prefix('queue-sessions')
            ->controller(QueueSessionController::class)
            ->group(function () {
                Route::get('/', 'index');
                Route::get('/statistics', 'statistics');
                Route::get('/{id}', 'show')->whereNumber('id');
                Route::get('/{id}/acquisition', 'acquisition')->whereNumber('id');

                Route::patch('/{id}/priority', 'updatePriority')->whereNumber('id')
                    ->middleware('role:facility_admin,operator,staff');

                Route::post('/{id}/cancel', 'cancel')->whereNumber('id')
                    ->middleware('role:facility_admin,operator,staff');
            });

        Route::prefix('customers')->controller(CustomerController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/statistics', 'statistics');
            Route::post('/', 'store');
            Route::get('/{id}', 'show')->whereNumber('id');
            Route::put('/{id}', 'update')->whereNumber('id');
            Route::get('/{id}/queue-sessions', 'queueSessions')->whereNumber('id');
        });

        Route::prefix('departments')
            ->controller(DepartmentController::class)
            ->group(function () {
                Route::get('/', 'index')
                    ->middleware('role:facility_admin,operator,staff');
                Route::get('/options', 'options')
                    ->middleware('role:facility_admin,operator,staff');
                Route::post('/', 'store')->middleware('role:facility_admin');
                Route::get('/{id}', 'show')->whereNumber('id')
                    ->middleware('role:facility_admin,operator,staff');
                Route::put('/{id}', 'update')->whereNumber('id')->middleware('role:facility_admin');
                Route::get('/{id}/stations', 'stations')->whereNumber('id')
                    ->middleware('role:facility_admin,operator,staff');
                Route::get('/{id}/workflows', 'workflows')->whereNumber('id')
                    ->middleware('role:facility_admin,operator,staff');
            });

        Route::get('/queue/monitor', [QueueMonitorController::class, 'index'])
            ->middleware(['role:facility_admin,operator,staff']);

        Route::prefix('stations')
            ->controller(StationController::class)
            ->group(function () {
                Route::get('/', 'index')->middleware('role:facility_admin,operator,staff');
                Route::get('/options', 'options')->middleware('role:facility_admin,operator,staff');
                Route::post('/', 'store')->middleware('role:facility_admin');
                Route::get('/{id}', 'show')->whereNumber('id')->middleware('role:facility_admin,operator,staff');
                Route::put('/{id}', 'update')->whereNumber('id')->middleware('role:facility_admin');
                Route::get('/{id}/queue', 'queue')->whereNumber('id')->middleware('role:facility_admin,operator,staff');
            });

        Route::prefix('workflows')
            ->controller(WorkflowController::class)
            ->group(function () {
                Route::get('/', 'index')->middleware('role:facility_admin,operator,staff');
                Route::get('/statistics', 'statistics')->middleware('role:facility_admin,operator,staff');
                Route::post('/', 'store')->middleware('role:facility_admin');
                Route::get('/{id}', 'show')->whereNumber('id')->middleware('role:facility_admin,operator,staff');
                Route::post('/{id}/steps', 'addStep')->whereNumber('id')->middleware('role:facility_admin');
            });

        Route::prefix('workflow-steps')
            ->controller(WorkflowController::class)
            ->group(function () {
                Route::put('/{id}', 'updateStep')->whereNumber('id')
                    ->middleware(['role:facility_admin']);
                Route::delete('/{id}', 'deleteStep')->whereNumber('id')
                    ->middleware(['role:facility_admin']);
            });

        Route::prefix('kiosk-devices')
            ->controller(KioskDeviceController::class)
            ->group(function () {
                Route::get('/', 'index')->middleware('role:facility_admin');
                Route::post('/', 'store')->middleware('role:facility_admin');
                Route::delete('/{id}', 'destroy')->whereNumber('id')->middleware('role:facility_admin');
                Route::post('/{id}/pairing-code', 'reissuePairingCode')->whereNumber('id')
                    ->middleware('role:facility_admin');
            });

        Route::prefix('display-devices')
            ->controller(DisplayDeviceController::class)
            ->group(function () {
                Route::get('/', 'index')->middleware('role:facility_admin');
                Route::post('/', 'store')->middleware('role:facility_admin');
                Route::delete('/{id}', 'destroy')->whereNumber('id')->middleware('role:facility_admin');
                Route::post('/{id}/pairing-code', 'reissuePairingCode')->whereNumber('id')
                    ->middleware('role:facility_admin');
            });

        Route::prefix('reports')
            ->controller(ReportController::class)
            ->group(function () {
                Route::get('/summary', 'summary')
                    ->middleware(['role:facility_admin,operator', 'feature:report_queue_overview']);
                Route::get('/queue-sessions/export', 'exportQueueSessions')
                    ->middleware(['role:facility_admin', 'feature:report_queue_overview', 'feature:report_export']);
                Route::post('/queue-sessions/export-file', 'exportFile')
                    ->middleware(['role:facility_admin', 'feature:report_queue_overview', 'feature:report_export']);
                Route::get('/download', 'download')
                    ->name('tenant.reports.download')
                    ->middleware(['role:facility_admin', 'feature:report_queue_overview', 'feature:report_export']);
            });

        Route::post('/whatsapp/messages', [WhatsAppController::class, 'send'])
            ->middleware(['role:facility_admin,operator', 'feature:whatsapp_notifications']);
    });
