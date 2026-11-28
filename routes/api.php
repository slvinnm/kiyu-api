<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API route loader
|--------------------------------------------------------------------------
|
| The API is split by application context. Each file is mounted under its own
| prefix and owns exactly one application context; no protected tenant route
| is exposed at the unqualified `/api/*` root.
|
*/

Route::prefix('public')->group(base_path('routes/api/public.php'));
Route::prefix('customer')->group(base_path('routes/api/customer.php'));
Route::prefix('app')->group(base_path('routes/api/app.php'));
Route::prefix('tenant')->group(base_path('routes/api/tenant.php'));
Route::prefix('portal')->group(base_path('routes/api/portal.php'));
Route::prefix('webhooks')->group(base_path('routes/api/webhooks.php'));
