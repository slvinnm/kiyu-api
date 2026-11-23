<?php

namespace App\Services;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manages a tenant's subscription.
 *
 * There is at most one effective subscription per tenant. That is enforced by
 * locking the tenant row and checking inside the transaction, not by a partial
 * unique index (which is not portable across database engines).
 */
class SubscriptionService
{
    /**
     * Start a trial, or activate the plan immediately when no trial is given.
     *
     * Any previously effective subscription is cancelled first, so history is
     * preserved and only one subscription is ever in force.
     */
    public function start(
        Tenant $tenant,
        Plan $plan,
        BillingInterval $interval,
        ?int $trialDays = null,
        ?string $currency = null,
    ): Subscription {
        if (! $plan->is_active) {
            throw new InvalidStateException("Plan '{$plan->code}' is not active.");
        }

        $price = $this->resolvePrice($plan, $interval, $currency);

        return DB::transaction(function () use ($tenant, $plan, $interval, $trialDays, $price): Subscription {
            $this->lockTenant($tenant);
            $this->cancelEffective($tenant);

            $now = Carbon::now();
            $isTrial = $trialDays !== null && $trialDays > 0;

            $subscription = new Subscription([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'plan_price_id' => $price?->id,
                'billing_interval' => $interval,
                'status' => $isTrial ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
                'cancel_at_period_end' => false,
            ]);

            if ($isTrial) {
                $subscription->trial_ends_at = $now->copy()->addDays($trialDays);
                $subscription->current_period_start = $now;
                $subscription->current_period_end = $subscription->trial_ends_at;
            } else {
                $subscription->current_period_start = $now;
                $subscription->current_period_end = $now->copy()->addMonths($interval->months());
            }

            $subscription->save();

            return $subscription;
        });
    }

    /**
     * Change the plan on the tenant's effective subscription.
     *
     * The old row is preserved; a new subscription row records the new plan and
     * period, keeping billing history intact.
     */
    public function changePlan(
        Tenant $tenant,
        Plan $plan,
        BillingInterval $interval,
        ?string $currency = null,
    ): Subscription {
        if (! $plan->is_active) {
            throw new InvalidStateException("Plan '{$plan->code}' is not active.");
        }

        $price = $this->resolvePrice($plan, $interval, $currency);

        return DB::transaction(function () use ($tenant, $plan, $interval, $price): Subscription {
            $this->lockTenant($tenant);
            $this->cancelEffective($tenant);

            $now = Carbon::now();

            $subscription = new Subscription([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'plan_price_id' => $price?->id,
                'billing_interval' => $interval,
                'status' => SubscriptionStatus::Active,
                'cancel_at_period_end' => false,
            ]);
            $subscription->current_period_start = $now;
            $subscription->current_period_end = $now->copy()->addMonths($interval->months());
            $subscription->save();

            return $subscription;
        });
    }

    /**
     * Cancel the tenant's effective subscription at the end of its period.
     */
    public function cancelAtPeriodEnd(Tenant $tenant): Subscription
    {
        return DB::transaction(function () use ($tenant): Subscription {
            $this->lockTenant($tenant);

            $subscription = $this->effectiveSubscription($tenant);

            if ($subscription === null) {
                throw new InvalidStateException('The tenant has no effective subscription to cancel.');
            }

            $subscription->update(['cancel_at_period_end' => true]);

            return $subscription;
        });
    }

    /**
     * Cancel the tenant's effective subscription immediately.
     */
    public function cancelNow(Tenant $tenant): Subscription
    {
        return DB::transaction(function () use ($tenant): Subscription {
            $this->lockTenant($tenant);

            $subscription = $this->effectiveSubscription($tenant);

            if ($subscription === null) {
                throw new InvalidStateException('The tenant has no effective subscription to cancel.');
            }

            $subscription->update([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => Carbon::now(),
            ]);

            return $subscription;
        });
    }

    /**
     * The tenant's effective subscription, if any.
     */
    public function effectiveSubscription(Tenant $tenant): ?Subscription
    {
        return Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', SubscriptionStatus::effectiveValues())
            ->latest('id')
            ->first();
    }

    /**
     * Whether the tenant's effective subscription grants the given feature.
     */
    public function hasFeature(Tenant $tenant, string $featureCode): bool
    {
        $subscription = $this->effectiveSubscription($tenant);

        if ($subscription === null || ! $subscription->isEffective()) {
            return false;
        }

        return $subscription->plan
            ->entitlements()
            ->where('feature_code', $featureCode)
            ->exists();
    }

    /**
     * Resolve the matching price row, if one is configured.
     */
    private function resolvePrice(Plan $plan, BillingInterval $interval, ?string $currency): ?PlanPrice
    {
        $query = $plan->prices()
            ->where('billing_interval', $interval->value)
            ->where('is_active', true);

        if ($currency !== null) {
            $query->where('currency', $currency);
        }

        return $query->orderBy('id')->first();
    }

    /**
     * Lock the tenant row so two concurrent subscription writes serialise.
     */
    private function lockTenant(Tenant $tenant): void
    {
        Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Cancel any subscription currently in force, preserving the row.
     */
    private function cancelEffective(Tenant $tenant): void
    {
        if ($this->effectiveSubscription($tenant) === null) {
            return;
        }

        Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', SubscriptionStatus::effectiveValues())
            ->update([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => Carbon::now(),
            ]);
    }
}
