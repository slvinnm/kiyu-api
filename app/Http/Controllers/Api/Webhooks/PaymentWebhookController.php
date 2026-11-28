<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provider webhook receiver.
 *
 * Public route, no auth: the payload is untrusted until the gateway verifies
 * its signature inside PaymentService. Replays are idempotent.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * Handle a provider webhook.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = (array) $request->all();

        // The gateway name comes from the URL segment, not the body.
        $provider = (string) $request->route('provider', 'midtrans');

        $this->payments->handleWebhook($provider, $payload);

        return response()->json(['message' => 'OK']);
    }
}
