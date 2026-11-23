<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\KioskDevice;

/**
 * Resolves a registered, paired kiosk device from its `X-Kiosk-Token` header.
 *
 * The single place both the strict kiosk middleware and the public-tenant
 * middleware use, so the lookup, usability and facility-binding rules cannot
 * drift apart.
 */
class KioskDeviceResolver
{
    /**
     * Resolve the device for the given raw credential, or null when no
     * credential was sent.
     *
     * @throws InvalidStateException when a credential is sent but unusable
     */
    public function resolve(?string $rawToken): ?KioskDevice
    {
        if ($rawToken === null || $rawToken === '') {
            return null;
        }

        /** @var KioskDevice|null $device */
        $device = KioskDevice::query()
            ->with(['tenant', 'facility'])
            ->where('credential_hash', hash('sha256', $rawToken))
            ->first();

        if ($device === null || ! $device->isUsable()) {
            throw new InvalidStateException('The kiosk device credential is invalid or revoked.');
        }

        if ($device->facility === null || $device->facility->tenant_id !== $device->tenant_id) {
            throw new InvalidStateException('The kiosk device is not bound to a valid facility.');
        }

        if (! $device->tenant->status->isOperational()) {
            throw new InvalidStateException('The kiosk device belongs to a tenant that is not active.');
        }

        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $device;
    }
}
