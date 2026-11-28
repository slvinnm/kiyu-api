<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchased add-on, always attached to a subscription and following its
 * billing lifecycle (no independent period of its own).
 */
class SubscriptionAddon extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'addon_id',
        'addon_price_id',
        'status',
        'quantity',
        'starts_at',
        'ends_at',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(AddonPrice::class, 'addon_price_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(SubscriptionAddonEntitlement::class);
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(AddonUsageEvent::class);
    }

    /**
     * Whether the add-on currently grants access.
     */
    public function isActive(): bool
    {
        return $this->status === 'ACTIVE'
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
