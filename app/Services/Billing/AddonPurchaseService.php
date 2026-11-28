<?php

namespace App\Services\Billing;

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Exceptions\InvalidStateException;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionAddonEntitlement;
use App\Models\Tenant;
use App\Services\Access\EntitlementResolver;
use App\Services\Access\FeatureAccessEngine;
use App\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Add-on eligibility and purchase.
 *
 * An add-on is always attached to the tenant's granting subscription and
 * follows its billing interval. Purchase is gated on: the plan being entitled
 * to the add-on (plan_addon_eligibilities), the add-on feature being released,
 * and the plan not already including the same entitlement (except capacity/quota
 * packs, which are additive). Activation happens only after a verified payment;
 * this service snapshots the grants (PRD §7, §11.2).
 */
class AddonPurchaseService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly FeatureAccessEngine $engine,
    ) {}

    /**
     * Add-ons the tenant's effective plan may buy.
     *
     * @return Collection<int, Addon>
     */
    public function eligibleFor(Tenant $tenant): Collection
    {
        $subscription = $this->subscriptions->grantingSubscription($tenant);

        if ($subscription === null) {
            return Addon::query()->whereRaw('1 = 0')->get();
        }

        $plan = $subscription->plan;

        return Addon::query()
            ->where('is_active', true)
            ->whereHas('eligibilities', fn($q) => $q->where('plan_id', $plan->id))
            ->with(['prices' => fn($q) => $q->where('is_active', true), 'entitlements'])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Validate and create a pending subscription add-on for a verified purchase.
     *
     * The price must be active for the subscription's billing interval. Returns
     * the row that a payment will activate; it grants nothing until then.
     */
    public function purchase(Tenant $tenant, Addon $addon, int $quantity = 1): SubscriptionAddon
    {
        $subscription = $this->subscriptions->grantingSubscription($tenant);

        if ($subscription === null) {
            throw new InvalidStateException('The tenant has no active subscription to attach an add-on to.');
        }

        $this->assertEligible($subscription, $addon);
        $this->assertNotDuplicate($subscription, $addon);

        $price = $this->priceFor($addon, $subscription->billing_interval);

        if ($price === null) {
            throw new InvalidStateException("Add-on '{$addon->code}' has no active price for this interval.");
        }

        return $subscription->addons()->create([
            'addon_id' => $addon->id,
            'addon_price_id' => $price->id,
            'status' => 'PENDING_PAYMENT',
            'quantity' => max(1, $quantity),
            'starts_at' => null,
            'ends_at' => null,
            'current_period_start' => $subscription->current_period_start,
            'current_period_end' => $subscription->current_period_end,
            'cancel_at_period_end' => false,
        ]);
    }

    /**
     * Activate a purchased add-on after a verified payment and snapshot grants.
     */
    public function activate(SubscriptionAddon $subscriptionAddon): SubscriptionAddon
    {
        $subscription = $subscriptionAddon->subscription;
        $now = Carbon::now();

        $subscriptionAddon->update([
            'status' => 'ACTIVE',
            'starts_at' => $now,
            'ends_at' => $subscription->current_period_end,
        ]);

        foreach ($subscriptionAddon->addon->entitlements as $entitlement) {
            SubscriptionAddonEntitlement::query()->updateOrCreate(
                [
                    'subscription_addon_id' => $subscriptionAddon->id,
                    'feature_code' => $entitlement->feature_code,
                ],
                ['value' => $entitlement->value],
            );
        }

        // An add-on adds entitlement; re-resolve the tenant cache.
        app(EntitlementResolver::class)->forget($subscription->tenant);

        return $subscriptionAddon->fresh();
    }

    /**
     * Schedule an add-on to cancel at the end of the current period.
     */
    public function cancelAtPeriodEnd(SubscriptionAddon $subscriptionAddon): SubscriptionAddon
    {
        $subscriptionAddon->update(['cancel_at_period_end' => true]);

        return $subscriptionAddon->fresh();
    }

    /**
     * Ensure the plan is eligible for the add-on.
     */
    private function assertEligible(Subscription $subscription, Addon $addon): void
    {
        abort_unless($addon->is_active, 422, 'This add-on is not available.');

        $eligible = $addon->eligibilities()->where('plan_id', $subscription->plan_id)->exists();

        abort_unless($eligible, 422, 'Your plan cannot buy this add-on.');

        // Every feature the add-on grants must be released globally.
        foreach ($addon->entitlements as $entitlement) {
            $feature = Feature::tryFrom($entitlement->feature_code);

            abort_if($feature === null, 422, 'This add-on references an unknown feature.');

            abort_if(
                $this->engine->isReleased($feature) === false,
                422,
                'This add-on is not available yet.',
            );
        }
    }

    /**
     * Refuse to sell a boolean entitlement the plan already grants.
     *
     * Capacity/quota packs may be bought repeatedly (quantity is additive).
     */
    private function assertNotDuplicate(Subscription $subscription, Addon $addon): void
    {
        $existing = $subscription->addons()
            ->where('addon_id', $addon->id)
            ->whereIn('status', ['ACTIVE', 'PENDING_PAYMENT'])
            ->exists();

        abort_if($existing, 422, 'This add-on is already active or pending on your subscription.');

        // A boolean report the plan already includes is not sold again.
        $map = app(EntitlementResolver::class)->allFor($subscription->tenant);

        foreach ($addon->entitlements as $entitlement) {
            $feature = Feature::tryFrom($entitlement->feature_code);

            if ($feature !== null && $feature->isToggle() && array_key_exists($feature->value, $map)) {
                abort(422, 'Your plan already includes this feature.');
            }
        }
    }

    private function priceFor(Addon $addon, BillingInterval $interval): ?AddonPrice
    {
        return $addon->prices()
            ->where('billing_interval', $interval->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
