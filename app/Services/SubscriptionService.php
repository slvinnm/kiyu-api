<?php

namespace App\Services;

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessConflictException;
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
     * Start a trial, or a paid subscription that awaits its first payment.
     *
     * A subscription without a trial is created PastDue and grants nothing:
     * only a verified provider webhook (see PaymentService) marks it Active.
     * Anything else would hand out a paid plan for free.
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

        // A paid plan with no price row could be created but never charged,
        // leaving the tenant permanently unable to activate it.
        if ($trialDays === null && $price === null) {
            throw new InvalidStateException(
                "Plan '{$plan->code}' has no active price for the {$interval->value} interval.",
            );
        }

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
                'status' => $isTrial ? SubscriptionStatus::Trialing : SubscriptionStatus::PastDue,
                'cancel_at_period_end' => false,
            ]);

            if ($isTrial) {
                $subscription->trial_ends_at = $now->copy()->addDays($trialDays);
                $subscription->current_period_start = $now;
                $subscription->current_period_end = $subscription->trial_ends_at;
            }

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
            ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value])
            ->where(function ($query): void {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>', now());
            })
            ->latest('id')
            ->first();
    }

    /**
     * Whether the tenant's effective subscription grants the given feature.
     */
    public function hasFeature(Tenant $tenant, Feature $feature): bool
    {
        return $this->effectiveSubscription($tenant)?->hasFeature($feature) ?? false;
    }

    /**
     * The numeric cap granted for a limit feature (0 when not granted).
     */
    public function limit(Tenant $tenant, Feature $feature): int
    {
        return $this->effectiveSubscription($tenant)?->featureLimit($feature)
            ?? $feature->defaultLimit();
    }

    /**
     * Current usage of a limited resource for the tenant.
     *
     * Only resources that are actually in use count against the quota. A
     * device that is inactive (revoked) or past its expiry no longer occupies a
     * slot, so deactivating one frees the seat for a replacement (PRD §15).
     */
    public function usage(Tenant $tenant, Feature $feature): int
    {
        return match ($feature) {
            Feature::MaxFacilities => $tenant->facilities()->count(),
            Feature::MaxStaff => $tenant->users()->where('role', '!=', UserRole::Customer->value)->count(),
            Feature::MaxKioskDevices => $this->activeDeviceCount($tenant, 'kiosk'),
            Feature::MaxDisplayDevices => $this->activeDeviceCount($tenant, 'display'),
            default => 0,
        };
    }

    /**
     * Count devices that consume quota: active and not expired.
     *
     * A device mid-pairing (registered, not yet paired) still holds its slot so
     * two people cannot both claim the last seat with pending codes.
     */
    private function activeDeviceCount(Tenant $tenant, string $kind): int
    {
        $query = $kind === 'kiosk'
            ? $tenant->kioskDevices()
            : $tenant->displayDevices();

        return $query
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();
    }

    /**
     * Whether the tenant may add one more unit of the limited resource.
     *
     * Limits are only enforced once the tenant has an effective subscription.
     * A freshly onboarded tenant without a plan is not quota-blocked, so
     * provisioning and pre-billing setups keep working.
     */
    public function hasCapacityFor(Tenant $tenant, Feature $feature): bool
    {
        if ($this->effectiveSubscription($tenant) === null) {
            return true;
        }

        return $this->usage($tenant, $feature) < $this->limit($tenant, $feature);
    }

    /**
     * Throw when the tenant is at its limit for the feature.
     *
     * Controllers call this before creating a limited resource so the check
     * lives in one place rather than being duplicated per endpoint.
     *
     * @throws BusinessConflictException
     */
    public function ensureCapacity(Tenant $tenant, Feature $feature): void
    {
        if (! $this->hasCapacityFor($tenant, $feature)) {
            throw new BusinessConflictException(
                sprintf(
                    'Your plan allows at most %d %s. Upgrade to add more.',
                    $this->limit($tenant, $feature),
                    str_replace('max_', '', $feature->value),
                ),
            );
        }
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
