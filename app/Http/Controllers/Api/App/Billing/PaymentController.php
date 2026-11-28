<?php

namespace App\Http\Controllers\Api\App\Billing;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

/**
 * Start a payment for the tenant's subscription. Admin only (enforced in
 * routes). The returned token/redirect_url is handed to the client SDK.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * Create a subscription charge.
     */
    public function charge(): JsonResponse
    {
        $charge = $this->payments->startCharge($this->tenant());

        return response()->json([
            'message' => 'Charge created.',
            'data' => $charge,
        ], 201);
    }
}
