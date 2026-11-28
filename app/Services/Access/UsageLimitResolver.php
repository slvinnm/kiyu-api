<?php

namespace App\Services\Access;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\Tenant;

/**
 * Counts the tenant's current usage of each capacity feature.
 *
 * Only resources that actually occupy a slot are counted, matching the existing
 * capacity semantics: a revoked/expired device frees its seat; customers do not
 * consume a staff seat.
 */
class UsageLimitResolver
{
    /**
     * Current usage of a capacity feature.
     */
    public function usage(Tenant $tenant, Feature $feature): int
    {
        return match ($feature) {
            Feature::MaxFacilities => $tenant->facilities()->count(),
            Feature::MaxStaff => $tenant->users()->where('role', '!=', UserRole::Customer->value)->count(),
            Feature::MaxKioskDevices => $this->activeDeviceCount($tenant, 'kiosk'),
            Feature::MaxDisplayDevices => $this->activeDeviceCount($tenant, 'display'),
            default => 0,
        };
    }

    /**
     * Count devices that consume quota: active and not expired.
     */
    private function activeDeviceCount(Tenant $tenant, string $kind): int
    {
        $query = $kind === 'kiosk'
            ? $tenant->kioskDevices()
            : $tenant->displayDevices();

        return $query
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();
    }
}
