<?php

namespace App\Models;

use App\Enums\BillingInterval;
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
     * The entitlements snapshotted onto this subscription at activation.
     *
     * Feature decisions read this snapshot (plus active add-on snapshots) via the
     * Feature Access Engine; the subscription model deliberately does not read
     * the mutable plan catalogue.
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(SubscriptionEntitlement::class);
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
     * The add-ons purchased on this subscription.
     */
    public function addons(): HasMany
    {
        return $this->hasMany(SubscriptionAddon::class);
    }
}
