<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price version for an add-on. Old rows are deactivated, never mutated, so a
 * payment that referenced them keeps its meaning.
 */
class AddonPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'addon_id',
        'billing_interval',
        'amount',
        'currency',
        'provider',
        'provider_price_id',
        'is_active',
    ];

    protected $casts = [
        'billing_interval' => BillingInterval::class,
        'amount' => 'integer',
        'is_active' => 'boolean',
    ];

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}
