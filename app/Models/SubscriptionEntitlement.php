<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshotted entitlement granted to a subscription at activation.
 */
class SubscriptionEntitlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'feature_code',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
