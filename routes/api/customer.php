<?php

use App\Http\Controllers\Api\Customer\CustomerAuthController;
use App\Http\Controllers\Api\Customer\ProfileController;
use App\Http\Controllers\Api\Customer\SessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer self-service context
|--------------------------------------------------------------------------
|
| A fifth, self-service context besides the four application groups. Bound to
| the tenant origin and issued its own `ctx:customer` token; a customer may only
| ever reach their own profile, own visits and own bookings.
|
*/

Route::prefix('auth')->controller(CustomerAuthController::class)->group(function () {
    Route::post('/register', 'register')->middleware('throttle:3,60');
    Route::post('/login', 'login')->middleware('throttle:6,1');
});

Route::middleware(['auth:sanctum', 'context:customer', 'tenant.bind', 'tenant', 'role:customer'])
    ->group(function () {
        Route::prefix('auth')->controller(CustomerAuthController::class)->group(function () {
            Route::get('/me', 'me');
            Route::post('/logout', 'logout');
        });

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::post('/profile', [ProfileController::class, 'store']);
        Route::put('/profile', [ProfileController::class, 'update']);

        Route::get('/queue-sessions', [SessionController::class, 'index']);
        // Booking names its facility via `facility_id` in the body (no header).
        Route::post('/bookings', [SessionController::class, 'book']);
        Route::get('/queue-sessions/{id}', [SessionController::class, 'show'])->whereNumber('id');
    });
