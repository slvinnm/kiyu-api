<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A public queue-display screen at a facility.
 *
 * Authenticates like a kiosk device: an admin registers it, an operator
 * redeems a short pairing code for a long-lived credential stored on the
 * screen. It only reads public queue data, never accepts input.
 */
class DisplayDevice extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'name',
        'credential_hash',
        'pairing_code_hash',
        'pairing_expires_at',
        'paired_at',
        'is_active',
        'last_seen_at',
        'expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'credential_hash',
        'pairing_code_hash',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
        'pairing_expires_at' => 'datetime',
        'paired_at' => 'datetime',
    ];

    /**
     * Whether the display has redeemed a pairing code and holds a real
     * credential.
     */
    public function isPaired(): bool
    {
        return $this->paired_at !== null;
    }

    /**
     * Determine whether the display credential may currently be used.
     */
    public function isUsable(): bool
    {
        return $this->isPaired()
            && $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Whether the display is waiting for an operator to redeem its code.
     */
    public function isAwaitingPairing(): bool
    {
        return ! $this->isPaired()
            && $this->is_active
            && $this->pairing_code_hash !== null
            && $this->pairing_expires_at !== null
            && $this->pairing_expires_at->isFuture();
    }

    /**
     * Get the tenant that registered the display.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the facility the display is mounted at.
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }
}
