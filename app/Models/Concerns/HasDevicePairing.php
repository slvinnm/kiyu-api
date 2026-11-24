<?php

namespace App\Models\Concerns;

use App\Models\Facility;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared lifecycle of a device that trades a short pairing code for a
 * long-lived credential (kiosk and display devices).
 *
 * The rules are identical for both, so they live here once: a registered but
 * unpaired device must never authenticate, an expired or revoked device is
 * unusable, and both rows are owned by a tenant and mounted at a facility.
 */
trait HasDevicePairing
{
    /**
     * Whether the device has redeemed a pairing code and holds a real
     * credential. An unpaired device stores only a placeholder hash.
     */
    public function isPaired(): bool
    {
        return $this->paired_at !== null;
    }

    /**
     * Determine whether the device credential may currently be used.
     *
     * The device must be paired, active, and (when set) not past its expiry.
     */
    public function isUsable(): bool
    {
        return $this->isPaired()
            && $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Whether the device is waiting for an operator to redeem its code.
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
     * Get the tenant that registered the device.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the facility the device is bound to.
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }
}
