<?php

namespace App\Http\Controllers\Api\App\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Start a payment for the tenant's subscription and read its own payment
 * history. Admin only (enforced in routes).
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

    /**
     * The tenant's own payment history.
     */
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->whereHas('subscription', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->withCount('items')
            ->latest('id')
            ->paginate(25);

        return response()->json(['data' => $payments]);
    }

    /**
     * One of the tenant's own payments with its line items.
     */
    public function show(int $id): JsonResponse
    {
        $payment = Payment::query()
            ->whereHas('subscription', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->with('items')
            ->findOrFail($id);

        return response()->json(['data' => $payment]);
    }
}
