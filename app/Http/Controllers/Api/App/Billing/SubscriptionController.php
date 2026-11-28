<?php

namespace App\Http\Controllers\Api\App\Billing;

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
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
                'entitlements' => $this->entitlementMap($tenant),
            ],
        ]);
    }

    /**
     * The tenant's resolved feature entitlements, for navigation and gating.
     *
     * A single map of `feature_code => bool` (toggles) and
     * `feature_code => int` (limits). Advanced reporting implies basic
     * reporting, so a tenant on an advanced plan is never denied the basic
     * reports menu (PRD §10.6 hierarchy).
     *
     * @return array<string, bool|int>
     */
    public function entitlements(): JsonResponse
    {
        $tenant = $this->tenant();

        return response()->json([
            'data' => $this->entitlementMap($tenant),
        ]);
    }

    /**
     * The plan catalogue a tenant may subscribe to (PRD §8.2 Billing).
     *
     * Read-only: active plans with their active prices and entitlements, plus
     * which one is the tenant's current plan. No tenant identifiers or secrets.
     */
    public function plans(): JsonResponse
    {
        $tenant = $this->tenant();
        $current = $this->subscriptions->effectiveSubscription($tenant);

        $plans = Plan::query()
            ->where('is_active', true)
            ->with([
                'prices' => fn($q) => $q->where('is_active', true)->orderBy('amount'),
                'entitlements',
            ])
            ->orderBy('name')
            ->get()

            ->map(fn(Plan $plan): array => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'is_active' => $plan->is_active,
                'is_current' => $current?->plan_id === $plan->id,
                'prices' => $plan->prices,
                'entitlements' => $plan->entitlements,
            ]);

        return response()->json([
            'data' => [
                'plans' => $plans,
                'current_plan_id' => $current?->plan_id,
                'subscription_status' => $current?->status?->value,
            ],
        ]);
    }

    /**
     * @return array<string, bool|int>
     */
    private function entitlementMap(Tenant $tenant): array
    {
        $map = [];

        foreach (Feature::cases() as $feature) {
            $map[$feature->value] = $feature->isLimit()
                ? $this->subscriptions->limit($tenant, $feature)
                : $this->subscriptions->hasFeature($tenant, $feature);
        }

        // Advanced reporting implies the basic reports menu.
        if (! empty($map[Feature::AdvancedReports->value])) {
            $map[Feature::BasicReports->value] = true;
        }

        return $map;
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
