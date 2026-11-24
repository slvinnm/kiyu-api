<?php

namespace App\Http\Controllers\Api\Billing;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant self-service subscription endpoints.
 *
 * Runs inside the resolved tenant context. A tenant can see its own
 * subscription and switch plans, but cannot touch another tenant's billing.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * The tenant's current subscription and its plan entitlements.
     */
    public function show(): JsonResponse
    {
        $tenant = $this->tenant();
        $subscription = $this->subscriptions->effectiveSubscription($tenant);

        return response()->json([
            'data' => [
                'subscription' => $subscription?->load('plan.entitlements'),
                'is_effective' => $subscription?->isEffective() ?? false,
            ],
        ]);
    }

    /**
     * Switch the tenant to a plan (trial or immediate activation).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', 'string'],
            'billing_interval' => ['required', 'string', 'in:MONTHLY,YEARLY'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $plan = Plan::query()
            ->where('code', $validated['plan_code'])
            ->where('is_active', true)
            ->firstOrFail();

        $tenant = $this->tenant();

        $subscription = $this->subscriptions->start(
            tenant: $tenant,
            plan: $plan,
            interval: BillingInterval::from($validated['billing_interval']),
            trialDays: $validated['trial_days'] ?? null,
            currency: $validated['currency'] ?? null,
        );

        return response()->json([
            'message' => $subscription->status === SubscriptionStatus::Trialing
                ? 'Subscription started.'
                : 'Subscription created. Complete the payment to activate it.',
            'data' => [
                ...$subscription->load('plan.entitlements')->toArray(),
                'is_effective' => $subscription->isEffective(),
                'payment_required' => ! $subscription->isEffective(),
            ],
        ], 201);
    }

    /**
     * Cancel the tenant's subscription at the end of the period.
     */
    public function cancel(): JsonResponse
    {
        $tenant = $this->tenant();

        $subscription = $this->subscriptions->cancelAtPeriodEnd($tenant);

        return response()->json([
            'message' => 'Subscription will cancel at the end of the current period.',
            'data' => $subscription,
        ]);
    }
}
