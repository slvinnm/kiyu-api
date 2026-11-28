<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line item on a payment, with a price snapshot so later catalogue edits do
 * not rewrite a historical payment.
 */
class PaymentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'plan_price_id',
        'addon_price_id',
        'subscription_addon_id',
        'name_snapshot',
        'unit_amount',
        'quantity',
        'total_amount',
        'currency',
    ];

    protected $casts = [
        'unit_amount' => 'integer',
        'quantity' => 'integer',
        'total_amount' => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
