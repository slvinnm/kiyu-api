<?php

namespace App\Services\Configuration;

use App\Models\Department;
use Illuminate\Database\Eloquent\Collection;

/**
 * Department read/write operations scoped to one facility.
 *
 * Shared by the App configuration controller and the Tenant workspace
 * controller. The facility id is always supplied by the caller from an
 * authorized context, never from a request body.
 */
class DepartmentService
{
    /**
     * Active kiosk-visible departments with their stations.
     *
     * @return Collection<int, Department>
     */
    public function activeForFacility(int $facilityId): Collection
    {
        return Department::query()
            ->where('facility_id', $facilityId)
            ->with('stations')
            ->where('is_active', true)
            ->where('display_in_kiosk', true)
            ->get();
    }

    /**
     * Lightweight department choices for select inputs.
     *
     * @return Collection<int, Department>
     */
    public function optionsForFacility(int $facilityId): Collection
    {
        return Department::query()
            ->where('facility_id', $facilityId)
            ->withCount('stations')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_active', 'display_in_kiosk']);
    }

    /**
     * One department of the facility with its stations and workflows.
     */
    public function find(int $facilityId, int $id): Department
    {
        return Department::query()
            ->where('facility_id', $facilityId)
            ->with(['stations', 'workflows.steps.station'])
            ->findOrFail($id);
    }

    /**
     * Create a department in the facility.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(int $facilityId, array $data): Department
    {
        $department = new Department([
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
            'display_in_kiosk' => $data['display_in_kiosk'] ?? true,
        ]);

        $department->facility_id = $facilityId;
        $department->save();

        return $department;
    }

    /**
     * Update a department of the facility.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $facilityId, int $id, array $data): Department
    {
        $department = Department::query()
            ->where('facility_id', $facilityId)
            ->findOrFail($id);

        $department->update($data);

        return $department;
    }
}
