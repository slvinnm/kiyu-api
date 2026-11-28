<?php

namespace App\Http\Controllers\Api\App\Billing;

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Access\FeatureAccessEngine;
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
        private readonly FeatureAccessEngine $engine,
    ) {}

    /**
     * The tenant's current subscription and its resolved entitlements.
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
     * The tenant's resolved feature entitlements.
     *
     * Each feature is an object distinguishing release, entitlement and
     * availability, with limit/used/remaining for capacity and quota (PRD §13.3).
     * Every value comes from the Feature Access Engine, never a raw catalogue
     * read, so an unreleased or unentitled feature is never reported available.
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
     * Per-feature entitlement objects, straight from the engine.
     *
     * @return array<string, array<string, mixed>>
     */
    private function entitlementMap(Tenant $tenant): array
    {
        $map = [];

        foreach (Feature::cases() as $feature) {
            $decision = $this->engine->canAccess($tenant, $feature);

            $entry = [
                'released' => $decision->isFeatureReleased,
                'available' => $decision->allowed,
                'entitled' => $decision->isEntitled,
                'sources' => $decision->sources,
                'type' => strtolower($feature->valueType()->value),
                'reason_code' => $decision->reasonCode->value,
            ];

            if (! $feature->isToggle()) {
                $entry['limit'] = $decision->limit ?? $this->engine->resolveLimit($tenant, $feature);
                $entry['used'] = $decision->used;
                $entry['remaining'] = $decision->remaining;
            }

            $map[$feature->value] = $entry;
        }

        return $map;
    }

    /**
     * Switch the tenant to a plan (paid; trial is signup-only).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', 'string'],
            'billing_interval' => ['required', 'string', 'in:MONTHLY,YEARLY'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $plan = Plan::query()
            ->where('code', $validated['plan_code'])
            ->where('is_active', true)
            ->firstOrFail();

        $tenant = $this->tenant();

        // Trial duration is never taken from the request: it is server-side only
        // and granted at signup/provisioning.
        $subscription = $this->subscriptions->start(
            tenant: $tenant,
            plan: $plan,
            interval: BillingInterval::from($validated['billing_interval']),
            trialDays: null,
            currency: $validated['currency'] ?? null,
        );

        return response()->json([
            'message' => 'Subscription created. Complete the payment to activate it.',
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
