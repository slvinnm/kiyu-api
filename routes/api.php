
<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClinicalQueueController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\IntakeController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PublicQueueController;
use App\Http\Controllers\Api\QueueAcquisitionController;
use App\Http\Controllers\Api\ReceptionController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VisitController;
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
    Route::post('/register', 'register')->middleware('throttle:6,1');

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
    Route::post('/kiosk', 'kiosk');
    Route::post('/online', 'online');
    Route::get('/online-lookup/{identifier}', 'onlineLookup');
    Route::post('/online-checkin', 'onlineCheckin');
});

// Public queue display (without personally identifiable information)
Route::get('/public/queue', [PublicQueueController::class, 'index']);

// Public organizational lookup for kiosk
Route::get('/departments/active', [DepartmentController::class, 'active']);

// Public visit tracking
Route::get('/visits/{token}/track', [VisitController::class, 'track']);

/*
|--------------------------------------------------------------------------
| Authenticated Endpoints
|--------------------------------------------------------------------------
|
| All endpoints below require a valid Laravel Sanctum token.
|
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Intake Management
    |--------------------------------------------------------------------------
    |
    | Manual patient registration and queue acquisition management.
    |
    */

    // Manual registration (reception)
    Route::post('/intake/manual', [IntakeController::class, 'manual']);

    // Queue acquisition records
    Route::prefix('queue-acquisitions')->controller(QueueAcquisitionController::class)->group(function () {
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

    Route::prefix('reception')->controller(ReceptionController::class)->group(function () {
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
    Route::post('/clinical/call-next', [ClinicalQueueController::class, 'callNext']);

    // Ticket records and lifecycle operations
    Route::prefix('tickets')->group(function () {
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
    | Patient referral records.
    |
    */

    Route::prefix('referrals')->controller(ReferralController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{id}', 'show')->whereNumber('id');
    });

    /*
    |--------------------------------------------------------------------------
    | Visit Management
    |--------------------------------------------------------------------------
    |
    | Patient visits, visit statistics, priority updates and cancellation.
    |
    */

    Route::prefix('visits')->controller(VisitController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/statistics', 'statistics');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::get('/{id}/acquisition', 'acquisition')->whereNumber('id');

        Route::patch('/{id}/priority', 'updatePriority')->whereNumber('id');
        Route::post('/{id}/cancel', 'cancel')->whereNumber('id');
    });

    /*
    |--------------------------------------------------------------------------
    | Patient Management
    |--------------------------------------------------------------------------
    |
    | Patient directory, patient records and visit history.
    |
    */

    Route::prefix('patients')->controller(PatientController::class)->group(function () {
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
        Route::post('/', 'store');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::put('/{id}', 'update')->whereNumber('id');

        Route::get('/{id}/stations', 'stations')->whereNumber('id');
        Route::get('/{id}/workflows', 'workflows')->whereNumber('id');
    });

    // Stations
    Route::prefix('stations')->controller(StationController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/options', 'options');
        Route::post('/', 'store');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::put('/{id}', 'update')->whereNumber('id');

        Route::get('/{id}/queue', 'queue')->whereNumber('id');
    });

    // Workflows
    Route::prefix('workflows')->controller(WorkflowController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/statistics', 'statistics');
        Route::post('/', 'store');
        Route::get('/{id}', 'show')->whereNumber('id');
        Route::post('/{id}/steps', 'addStep')->whereNumber('id');
    });

    // Workflow steps
    Route::prefix('workflow-steps')->controller(WorkflowController::class)->group(function () {
        Route::put('/{id}', 'updateStep')->whereNumber('id');
        Route::delete('/{id}', 'deleteStep')->whereNumber('id');
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

    Route::prefix('users')->controller(UserController::class)->group(function () {
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

    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
