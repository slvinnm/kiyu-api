<?php

namespace App\Models;

use App\Models\Concerns\HasDevicePairing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A public queue-display screen at a facility.
 *
 * Authenticates like a kiosk device: an admin registers it, an operator
 * redeems a short pairing code for a long-lived credential stored on the
 * screen. It only reads public queue data, never accepts input.
 */
class DisplayDevice extends Model
{
    use HasDevicePairing, HasFactory;

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
}
