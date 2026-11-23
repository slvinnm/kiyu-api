<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Public kiosk channels are scoped by tenant and facility slug, so a client
| can only reach the display of the clinic it is looking at. Staff channels
| are private and authorise the authenticated user against the channel's
| tenant.
|
*/

// Public display channel, one per facility. A guessed numeric id cannot reach
// another tenant: the facility slug is the routing key and no PII is broadcast.
Broadcast::channel('public.facility.{facilityId}', function ($user, int $facilityId) {
    if ($user instanceof User) {
        return $user->isTenantUser() && $user->tenant?->facilities()
            ->whereKey($facilityId)
            ->exists();
    }

    return true;
});

// Staff queue channels are private and tenant-bound.
Broadcast::channel('tenant.{tenantId}.department.{departmentId}', function (User $user, int $tenantId, int $departmentId) {
    if ($user->tenant_id !== $tenantId || ! $user->isTenantUser()) {
        return false;
    }

    return $user->tenant?->facilities()
        ->whereHas('departments', fn($query) => $query->whereKey($departmentId))
        ->exists();
});

Broadcast::channel('tenant.{tenantId}.station.{stationId}', function (User $user, int $tenantId, int $stationId) {
    if ($user->tenant_id !== $tenantId || ! $user->isTenantUser()) {
        return false;
    }

    return $user->tenant?->facilities()
        ->whereHas('departments.stations', fn($query) => $query->whereKey($stationId))
        ->exists();
});
