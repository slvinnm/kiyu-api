<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An add-on product in the catalogue.
 */
class Addon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(AddonPrice::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(AddonEntitlement::class);
    }

    public function eligibilities(): HasMany
    {
        return $this->hasMany(PlanAddonEligibility::class);
    }

    public function subscriptionAddons(): HasMany
    {
        return $this->hasMany(SubscriptionAddon::class);
    }
}
