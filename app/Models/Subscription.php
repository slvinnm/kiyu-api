<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'plan_id',
        'plan_price_id',
        'status',
        'billing_interval',
        'provider',
        'provider_customer_id',
        'provider_subscription_id',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'cancelled_at',
        'cancel_at_period_end',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => SubscriptionStatus::class,
        'billing_interval' => BillingInterval::class,
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];

    /**
     * Whether the subscription currently grants access.
     */
    public function isEffective(): bool
    {
        if (! $this->status->isEffective()) {
            return false;
        }

        // A trial is only effective until it ends.
        if ($this->status === SubscriptionStatus::Trialing
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Whether the subscription's plan grants the feature.
     *
     * For a toggle a granted row means "on"; for a limit a granted row means a
     * positive cap (a limit of 0 is treated as not granted).
     */
    public function hasFeature(Feature $feature): bool
    {
        if (! $this->isEffective()) {
            return false;
        }

        $entitlement = $this->plan->entitlements
            ->firstWhere('feature_code', $feature->value);

        if ($entitlement === null) {
            return false;
        }

        return $feature->isToggle() || $this->readLimit($entitlement) > 0;
    }

    /**
     * The numeric limit granted for a feature, or the feature's default when
     * the plan does not grant it.
     */
    public function featureLimit(Feature $feature): int
    {
        if (! $this->isEffective()) {
            return $feature->defaultLimit();
        }

        $entitlement = $this->plan->entitlements
            ->firstWhere('feature_code', $feature->value);

        if ($entitlement === null) {
            return $feature->defaultLimit();
        }

        return $this->readLimit($entitlement);
    }

    /**
     * Read the integer limit stored in an entitlement's value.
     */
    private function readLimit(PlanEntitlement $entitlement): int
    {
        $value = $entitlement->value ?? [];

        return (int) ($value['limit'] ?? 0);
    }

    /**
     * Get the tenant this subscription belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the plan this subscription is on.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the specific price row for this subscription, if any.
     */
    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class);
    }

    /**
     * Get the payments recorded for this subscription.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The entitlements snapshotted onto this subscription at activation.
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(SubscriptionEntitlement::class);
    }

    /**
     * The add-ons purchased on this subscription.
     */
    public function addons(): HasMany
    {
        return $this->hasMany(SubscriptionAddon::class);
    }
}
