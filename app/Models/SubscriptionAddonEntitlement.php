<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The entitlement(s) snapshotted onto a purchased add-on.
 */
class SubscriptionAddonEntitlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_addon_id',
        'feature_code',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function subscriptionAddon(): BelongsTo
    {
        return $this->belongsTo(SubscriptionAddon::class);
    }
}
