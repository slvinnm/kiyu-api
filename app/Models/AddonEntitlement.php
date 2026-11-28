<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The entitlement(s) an add-on product grants.
 */
class AddonEntitlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'addon_id',
        'feature_code',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}
