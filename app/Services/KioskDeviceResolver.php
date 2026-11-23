<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\DisplayDevice;
use App\Models\KioskDevice;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a registered, paired device from its raw credential.
 *
 * Kiosk and display devices share the same rules — paired, active, not
 * expired, bound to a facility owned by an operational tenant — so one place
 * enforces them for both and the rules cannot drift apart.
 */
class KioskDeviceResolver
{
    /**
     * Resolve a kiosk device for the given raw credential, or null when none
     * was sent.
     *
     * @throws InvalidStateException when a credential is sent but unusable
     */
    public function resolveKiosk(?string $rawToken): ?KioskDevice
    {
        /** @var KioskDevice|null $device */
        $device = $this->resolve(KioskDevice::class, $rawToken, 'kiosk device');

        return $device;
    }

    /**
     * Resolve a display device for the given raw credential, or null when none
     * was sent.
     *
     * @throws InvalidStateException when a credential is sent but unusable
     */
    public function resolveDisplay(?string $rawToken): ?DisplayDevice
    {
        /** @var DisplayDevice|null $device */
        $device = $this->resolve(DisplayDevice::class, $rawToken, 'display device');

        return $device;
    }

    /**
     * @template TDevice of Model
     *
     * @param  class-string<TDevice>  $model
     * @return TDevice|null
     */
    private function resolve(string $model, ?string $rawToken, string $label): ?Model
    {
        if ($rawToken === null || $rawToken === '') {
            return null;
        }

        /** @var TDevice|null $device */
        $device = $model::query()
            ->with(['tenant', 'facility'])
            ->where('credential_hash', hash('sha256', $rawToken))
            ->first();

        if ($device === null || ! $device->isUsable()) {
            throw new InvalidStateException("The {$label} credential is invalid or revoked.");
        }

        if ($device->facility === null || $device->facility->tenant_id !== $device->tenant_id) {
            throw new InvalidStateException("The {$label} is not bound to a valid facility.");
        }

        if (! $device->tenant->status->isOperational()) {
            throw new InvalidStateException("The {$label} belongs to a tenant that is not active.");
        }

        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $device;
    }
}
