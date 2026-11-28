<?php

use App\Http\Controllers\Api\Public\TenantDiscoveryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public website context
|--------------------------------------------------------------------------
|
| Marketing/landing-page data only: no session, no tenant operational data.
| The tenant lookup proves a tenant *exists*; it never proves membership.
|
*/

Route::get('/tenants/{slug}', [TenantDiscoveryController::class, 'show'])
    ->middleware('throttle:120,1');
