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

// 0. Authentication
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// 1. Public Intake Channel Endpoints (Kiosk & Online booking)
Route::prefix('intake')->group(function () {
    Route::post('/kiosk', [IntakeController::class, 'kiosk']);
    Route::post('/online', [IntakeController::class, 'online']);
    Route::get('/online-lookup/{identifier}', [IntakeController::class, 'onlineLookup']);
    Route::post('/online-checkin', [IntakeController::class, 'onlineCheckin']);
});

// 5. Public Queue Display (No PII)
Route::get('/public/queue', [PublicQueueController::class, 'index']);

// 7a. Public organizational lookup (kiosk department picker)
Route::get('/departments/active', [DepartmentController::class, 'active']);

// 8a. Public visit tracking
Route::get('/visits/{token}/track', [VisitController::class, 'track']);

// Everything below requires an authenticated Sanctum token.
Route::middleware('auth:sanctum')->group(function () {
    // 0b. User management (admin only, enforced in the controller)
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{id}', [UserController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [UserController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [UserController::class, 'destroy'])->whereNumber('id');
    });

    // 1b. Manual intake (reception)
    Route::post('/intake/manual', [IntakeController::class, 'manual']);

    // 1c. Queue Acquisition Management
    Route::prefix('queue-acquisitions')->group(function () {
        Route::get('/', [QueueAcquisitionController::class, 'index']);
        Route::get('/{id}', [QueueAcquisitionController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/cancel', [QueueAcquisitionController::class, 'cancel'])->whereNumber('id');
    });

    // 2. Reception Queue & Registration Endpoints
    Route::prefix('reception')->group(function () {
        Route::get('/queue', [ReceptionController::class, 'queue']);

        Route::post('/call-next', [ReceptionController::class, 'callNext']);
        Route::post('/complete-registration', [ReceptionController::class, 'completeRegistration']);
        Route::post('/{ticketId}/recall', [ReceptionController::class, 'recall'])->whereNumber('ticketId');
        Route::post('/{ticketId}/skip', [ReceptionController::class, 'skip'])->whereNumber('ticketId');
        Route::post('/{ticketId}/no-show', [ReceptionController::class, 'noShow'])->whereNumber('ticketId');
    });

    // 3. Clinical Queue Operations
    Route::prefix('clinical')->group(function () {
        Route::post('/call-next', [ClinicalQueueController::class, 'callNext']);
    });

    Route::prefix('tickets')->group(function () {
        Route::get('/', [TicketController::class, 'index']);
        Route::get('/statistics', [TicketController::class, 'statistics']);
        Route::get('/{id}', [TicketController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/events', [TicketController::class, 'events'])->whereNumber('id');

        Route::post('/{id}/start', [ClinicalQueueController::class, 'start'])->whereNumber('id');
        Route::post('/{id}/hold', [ClinicalQueueController::class, 'hold'])->whereNumber('id');
        Route::post('/{id}/resume', [ClinicalQueueController::class, 'resume'])->whereNumber('id');
        Route::post('/{id}/recall', [ClinicalQueueController::class, 'recall'])->whereNumber('id');
        Route::post('/{id}/complete', [ClinicalQueueController::class, 'complete'])->whereNumber('id');
        Route::post('/{id}/skip', [ClinicalQueueController::class, 'skip'])->whereNumber('id');
        Route::post('/{id}/no-show', [ClinicalQueueController::class, 'noShow'])->whereNumber('id');
        Route::post('/{id}/cancel', [ClinicalQueueController::class, 'cancel'])->whereNumber('id');
        Route::post('/{id}/transfer', [ClinicalQueueController::class, 'transfer'])->whereNumber('id');
    });

    // 4. Referral Management
    Route::prefix('referrals')->group(function () {
        Route::get('/', [ReferralController::class, 'index']);
        Route::post('/', [ReferralController::class, 'store']);
        Route::get('/{id}', [ReferralController::class, 'show'])->whereNumber('id');
    });

    // 6. Visit Management
    Route::prefix('visits')->group(function () {
        Route::get('/', [VisitController::class, 'index']);
        Route::get('/statistics', [VisitController::class, 'statistics']);
        Route::get('/{id}', [VisitController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/acquisition', [VisitController::class, 'acquisition'])->whereNumber('id');
        Route::patch('/{id}/priority', [VisitController::class, 'updatePriority'])->whereNumber('id');
        Route::post('/{id}/cancel', [VisitController::class, 'cancel'])->whereNumber('id');
    });

    // 7. Organizational Setup (Departments, Stations, Workflows)
    Route::prefix('departments')->group(function () {
        Route::get('/', [DepartmentController::class, 'index']);
        Route::get('/options', [DepartmentController::class, 'options']);
        Route::post('/', [DepartmentController::class, 'store']);
        Route::get('/{id}', [DepartmentController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [DepartmentController::class, 'update'])->whereNumber('id');
        Route::get('/{id}/stations', [DepartmentController::class, 'stations'])->whereNumber('id');
        Route::get('/{id}/workflows', [DepartmentController::class, 'workflows'])->whereNumber('id');
    });

    Route::prefix('stations')->group(function () {
        Route::get('/', [StationController::class, 'index']);
        Route::get('/options', [StationController::class, 'options']);
        Route::post('/', [StationController::class, 'store']);
        Route::get('/{id}', [StationController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [StationController::class, 'update'])->whereNumber('id');
        Route::get('/{id}/queue', [StationController::class, 'queue'])->whereNumber('id');
    });

    Route::prefix('workflows')->group(function () {
        Route::get('/', [WorkflowController::class, 'index']);
        Route::get('/statistics', [WorkflowController::class, 'statistics']);
        Route::post('/', [WorkflowController::class, 'store']);
        Route::get('/{id}', [WorkflowController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/steps', [WorkflowController::class, 'addStep'])->whereNumber('id');
    });

    Route::prefix('workflow-steps')->group(function () {
        Route::put('/{id}', [WorkflowController::class, 'updateStep'])->whereNumber('id');
        Route::delete('/{id}', [WorkflowController::class, 'deleteStep'])->whereNumber('id');
    });

    // 8. Patient Directory
    Route::prefix('patients')->group(function () {
        Route::get('/', [PatientController::class, 'index']);
        Route::get('/statistics', [PatientController::class, 'statistics']);
        Route::post('/', [PatientController::class, 'store']);
        Route::get('/{id}', [PatientController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [PatientController::class, 'update'])->whereNumber('id');
        Route::get('/{id}/visits', [PatientController::class, 'visits'])->whereNumber('id');
    });

    // 9. Audit Logs
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
