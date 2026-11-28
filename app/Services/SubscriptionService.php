<?php

namespace App\Services;

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BusinessConflictException;
use App\Exceptions\InvalidStateException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Access\FeatureAccessEngine;
use App\Services\Access\UsageLimitResolver;
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
     * Start a trial, or a paid subscription that awaits its first payment.
     *
     * A paid subscription is created PENDING_PAYMENT and grants nothing: only a
     * verified provider webhook (see PaymentService::activate) marks it Active.
     *
     * A new subscription does NOT cancel the tenant's current one. The tenant
     * keeps its existing access until the new payment is verified, so an
     * abandoned or failed checkout never strands a paying customer. Superseding
     * the old subscription happens at activation, not here.
     *
     * Trial days are decided server-side by the caller (signup/provisioning);
     * this method trusts its own argument, never a client request.
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

        // A paid plan with no price row could be created but never charged,
        // leaving the tenant permanently unable to activate it.
        if ($trialDays === null && $price === null) {
            throw new InvalidStateException(
                "Plan '{$plan->code}' has no active price for the {$interval->value} interval.",
            );
        }

        return DB::transaction(function () use ($tenant, $plan, $interval, $trialDays, $price): Subscription {
            $this->lockTenant($tenant);

            $now = Carbon::now();
            $isTrial = $trialDays !== null && $trialDays > 0;

            // A trial cannot be started while another subscription still grants
            // access; the checkout path handles paid plan changes instead.
            if ($isTrial && $this->grantingSubscription($tenant) !== null) {
                throw new InvalidStateException('The tenant already has a granting subscription.');
            }

            // A trial is one per tenant: refuse if any trial ever existed, unless a
            // platform admin grants it explicitly (a separate audited path).
            if ($isTrial && $tenant->subscriptions()->whereNotNull('trial_ends_at')->exists()) {
                throw new InvalidStateException('This tenant has already used its trial.');
            }

            $subscription = new Subscription([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'plan_price_id' => $price?->id,
                'billing_interval' => $interval,
                'status' => $isTrial ? SubscriptionStatus::Trialing : SubscriptionStatus::PendingPayment,
                'cancel_at_period_end' => false,
            ]);

            if ($isTrial) {
                $subscription->trial_ends_at = $now->copy()->addDays($trialDays);
                $subscription->current_period_start = $now;
                $subscription->current_period_end = $subscription->trial_ends_at;
            }

            $subscription->save();

            // A trial is active immediately and snapshots its entitlements now.
            if ($isTrial) {
                $this->snapshotEntitlements($subscription);
            }

            return $subscription;
        });
    }

    /**
     * Activate a paid subscription after a verified payment.
     *
     * Snapshots the plan entitlements, sets the billing period, and supersedes
     * any other granting subscription so only one is ever in force. Runs inside
     * the webhook transaction; idempotent because activation sets the same final
     * state.
     */
    public function activate(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription): Subscription {
            $tenant = $subscription->tenant;

            $this->lockTenant($tenant);

            // Supersede the tenant's other granting subscriptions, keeping rows.
            Subscription::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', '!=', $subscription->id)
                ->whereIn('status', SubscriptionStatus::grantingValues())
                ->update(['status' => SubscriptionStatus::Cancelled->value, 'cancelled_at' => Carbon::now()]);

            $now = Carbon::now();

            $subscription->update([
                'status' => SubscriptionStatus::Active,
                'current_period_start' => $now,
                'current_period_end' => $now->copy()->addMonths($subscription->billing_interval->months()),
            ]);

            $this->snapshotEntitlements($subscription);

            return $subscription->fresh();
        });
    }

    /**
     * Copy the plan's catalogue entitlements onto the subscription snapshot.
     *
     * The snapshot is what the engine reads later, so catalogue edits never
     * retroactively change what was bought. Idempotent: re-running replaces the
     * same set.
     */
    public function snapshotEntitlements(Subscription $subscription): void
    {
        $planEntitlements = $subscription->plan->entitlements()->get();

        foreach ($planEntitlements as $entitlement) {
            $subscription->entitlements()->updateOrCreate(
                ['feature_code' => $entitlement->feature_code],
                ['value' => $entitlement->value],
            );
        }
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
     * The subscription that currently grants access (TRIALING/ACTIVE only).
     *
     * PAST_DUE and PENDING_PAYMENT occupy the tenant's slot but grant nothing, so
     * they are excluded here. An ended trial or paid period also grants nothing.
     */
    public function grantingSubscription(Tenant $tenant): ?Subscription
    {
        return Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($query): void {
                // ACTIVE: in period (or no period end set).
                $query->where(function ($q): void {
                    $q->where('status', SubscriptionStatus::Active->value)
                        ->where(fn($inner) => $inner->whereNull('current_period_end')->orWhere('current_period_end', '>', now()));
                })
                    // TRIALING: not past the trial end.
                    ->orWhere(function ($q): void {
                        $q->where('status', SubscriptionStatus::Trialing->value)
                            ->where(fn($inner) => $inner->whereNull('trial_ends_at')->orWhere('trial_ends_at', '>', now()));
                    });
            })
            ->latest('id')
            ->first();
    }

    /**
     * Whether the tenant's subscription grants the given feature.
     *
     * Delegates to the Feature Access Engine so release, entitlement snapshot and
     * dependency rules all apply; there is no plan-catalogue-only shortcut.
     */
    public function hasFeature(Tenant $tenant, Feature $feature): bool
    {
        return app(FeatureAccessEngine::class)->canAccess($tenant, $feature)->allowed;
    }

    /**
     * The numeric cap granted for a limit feature (0 when not granted).
     */
    public function limit(Tenant $tenant, Feature $feature): int
    {
        return app(FeatureAccessEngine::class)->resolveLimit($tenant, $feature);
    }

    /**
     * Current usage of a limited resource for the tenant.
     */
    public function usage(Tenant $tenant, Feature $feature): int
    {
        return app(UsageLimitResolver::class)->usage($tenant, $feature);
    }

    /**
     * Whether the tenant may add one more unit of the limited resource.
     *
     * Delegates to the engine. A tenant with no granting subscription is not
     * quota-blocked, matching the previous onboarding behaviour.
     */
    public function hasCapacityFor(Tenant $tenant, Feature $feature): bool
    {
        if ($this->grantingSubscription($tenant) === null) {
            return true;
        }

        if (! $this->hasFeature($tenant, $feature)) {
            return false;
        }

        return $this->usage($tenant, $feature) < $this->limit($tenant, $feature);
    }

    /**
     * Throw when the tenant is at its limit for the feature.
     *
     * Delegates to the engine; converts a denial into a 409 business conflict so
     * controllers keep their existing error handling.
     *
     * @throws BusinessConflictException
     */
    public function ensureCapacity(Tenant $tenant, Feature $feature): void
    {
        if ($this->hasCapacityFor($tenant, $feature)) {
            return;
        }

        throw new BusinessConflictException(
            sprintf(
                'Your plan allows at most %d %s. Upgrade to add more.',
                $this->limit($tenant, $feature),
                str_replace('max_', '', $feature->value),
            ),
        );
    }

    /**
     * Reserve a device-slot atomically and run the creation inside the lock.
     *
     * Two operators registering the last kiosk at the same moment must not both
     * pass the capacity check. The tenant row is locked for the duration of the
     * callback, so the count and insert are serialised (PRD §15).
     *
     * @template T
     *
     * @param  callable(): T  $create
     * @return T
     */
    public function reserveDeviceSlot(Tenant $tenant, Feature $feature, callable $create): mixed
    {
        return DB::transaction(function () use ($tenant, $feature, $create): mixed {
            $this->lockTenant($tenant);
            $this->ensureCapacity($tenant, $feature);

            return $create();
        });
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
