<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPrice extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'plan_id',
        'billing_interval',
        'amount',
        'currency',
        'provider',
        'provider_price_id',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'billing_interval' => BillingInterval::class,
        'amount' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the plan this price belongs to.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * A human-readable price, e.g. "IDR 150,000".
     */
    public function formattedAmount(): string
    {
        return sprintf('%s %s', $this->currency, number_format($this->amount / 100, 2));
    }
}
