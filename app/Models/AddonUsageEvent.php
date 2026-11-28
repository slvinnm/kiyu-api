<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quota-consumption ledger row. The unique `idempotency_key` makes a retried
 * job or webhook a no-op instead of a second consumption.
 */
class AddonUsageEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_addon_id',
        'feature_code',
        'quantity',
        'idempotency_key',
        'period_start',
        'period_end',
        'occurred_at',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'occurred_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function subscriptionAddon(): BelongsTo
    {
        return $this->belongsTo(SubscriptionAddon::class);
    }
}
