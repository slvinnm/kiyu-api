<?php

namespace App\Services\Auth;

use App\Models\Facility;
use App\Models\User;

/**
 * Shapes the user payload returned to API clients after authentication.
 *
 * Extracted from the old `AuthController` so every context's auth controller
 * returns the same shape without duplicating it. It reveals only the caller's
 * own identity and the facilities they can act at.
 */
class UserPresenter
{
    /**
     * Shape the user payload returned to API clients.
     *
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $user->loadMissing(['customer', 'tenant']);

        // Every facility the user may act at. A single entry is the implicit
        // context; several let the browser render a facility switcher.
        $facilityQuery = $user->hasTenantWideAccess()
            ? ($user->tenant?->facilities()->where('is_active', true)->orderBy('id') ?? null)
            : null;

        $facilities = $facilityQuery !== null
            ? $facilityQuery->get(['id', 'name', 'code'])
            : Facility::query()
                ->whereIn('id', $user->facilityAssignments()->select('facility_id'))
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'name', 'code']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'tenant_id' => $user->tenant_id,
            // Names for the sidebar header + facility switcher, so a workspace
            // role does not need tenant-wide facility-list permission to label
            // its own facility.
            'facilities' => $facilities->map(fn($facility): array => [
                'id' => $facility->id,
                'name' => $facility->name,
                'code' => $facility->code,
            ])->all(),
            'tenant' => $user->tenant === null ? null : [
                'id' => $user->tenant->id,
                'name' => $user->tenant->name,
                'slug' => $user->tenant->slug,
                'status' => $user->tenant->status?->value,
                'timezone' => $user->tenant->timezone,
            ],
            'facility_ids' => $facilities->pluck('id')->all(),
            'station_id' => $user->station_id,
            'profile' => $user->customer,
        ];
    }
}
