<?php

namespace App\Services\Configuration;

use App\Enums\StationType;
use App\Models\Department;
use App\Models\Station;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Station queries and writes scoped to one facility, plus the validation rule
 * shared by every endpoint that must only accept a station of that facility.
 *
 * The rule lived on the base controller before; it belongs with the station
 * domain so both the App and Tenant controllers reuse it verbatim.
 */
class StationService
{
    /**
     * A rule that only accepts a station inside the given facility.
     */
    public function inFacilityRule(int $facilityId): Exists
    {
        return Rule::exists('stations', 'id')->where(
            fn($query) => $query->whereIn(
                'department_id',
                Department::query()->where('facility_id', $facilityId)->select('id'),
            ),
        );
    }

    /**
     * Lightweight station choices for select inputs.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Station>
     */
    public function options(int $facilityId, array $filters): Collection
    {
        $query = Station::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $facilityId))
            ->orderBy('name');

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->get([
            'id',
            'department_id',
            'code',
            'name',
            'type',
            'queue_prefix',
            'is_active',
        ]);
    }

    /**
     * One station of the facility.
     */
    public function find(int $facilityId, int $id): Station
    {
        return Station::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $facilityId))
            ->with('department')
            ->findOrFail($id);
    }

    /**
     * Create a station in a department of the facility.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(int $facilityId, array $data): Station
    {
        $this->assertDepartmentInFacility($facilityId, (int) $data['department_id']);

        $station = Station::query()->create([
            'department_id' => $data['department_id'],
            'name' => $data['name'],
            'code' => $data['code'],
            'type' => $data['type'],
            'queue_prefix' => $data['queue_prefix'] ?? 'A',
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $station->load('department');
    }

    /**
     * Update a station of the facility.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $facilityId, int $id, array $data): Station
    {
        $station = $this->find($facilityId, $id);

        if (isset($data['department_id'])) {
            $this->assertDepartmentInFacility($facilityId, (int) $data['department_id']);
        }

        $station->update($data);

        return $station->load('department');
    }

    /**
     * The allowed station type values, for validation rules.
     *
     * @return list<string>
     */
    public function typeValues(): array
    {
        return array_column(StationType::cases(), 'value');
    }

    /**
     * Ensure a department belongs to the facility.
     */
    private function assertDepartmentInFacility(int $facilityId, int $departmentId): void
    {
        Department::query()
            ->where('facility_id', $facilityId)
            ->findOrFail($departmentId);
    }
}
