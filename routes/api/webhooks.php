<?php

use App\Http\Controllers\Api\Webhooks\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment provider webhooks
|--------------------------------------------------------------------------
|
| No user authentication. The provider is identified by the URL segment and
| the payload is untrusted until the gateway verifies its signature inside
| PaymentService. Replays are idempotent; no release/entitlement gate applies.
|
*/

Route::post('/payments/{provider}', [PaymentWebhookController::class, 'handle'])
    ->middleware('throttle:120,1');
