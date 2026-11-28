<?php

namespace App\Http\Controllers\Api\App\Billing;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\SubscriptionAddon;
use App\Services\Billing\AddonPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Add-on catalogue and purchase for the tenant's own subscription.
 *
 * The subscription is always the tenant's server-resolved effective one; the
 * request can never name a subscription or tenant. Activation happens through
 * the payment webhook, not here.
 */
class AddonController extends Controller
{
    public function __construct(
        private readonly AddonPurchaseService $addons,
    ) {}

    /**
     * Add-ons the tenant's plan is eligible to buy.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->addons->eligibleFor($this->tenant()),
        ]);
    }

    /**
     * Buy an add-on (creates a pending purchase awaiting payment).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'addon_id' => ['required', 'integer', 'exists:addons,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $addon = Addon::query()->findOrFail($validated['addon_id']);

        $purchase = $this->addons->purchase(
            tenant: $this->tenant(),
            addon: $addon,
            quantity: (int) ($validated['quantity'] ?? 1),
        );

        return response()->json([
            'message' => 'Add-on checkout created. Complete the payment to activate it.',
            'data' => $purchase,
        ], 201);
    }

    /**
     * Schedule an add-on to cancel at the end of the period.
     */
    public function cancel(int $id): JsonResponse
    {
        $purchase = SubscriptionAddon::query()
            ->whereHas('subscription', fn($q) => $q->where('tenant_id', $this->tenantId()))
            ->findOrFail($id);

        $purchase = $this->addons->cancelAtPeriodEnd($purchase);

        return response()->json([
            'message' => 'Add-on will cancel at the end of the current period.',
            'data' => $purchase,
        ]);
    }
}
