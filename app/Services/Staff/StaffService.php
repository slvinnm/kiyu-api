<?php

namespace App\Services\Staff;

use App\Enums\Feature;
use App\Enums\StationType;
use App\Enums\UserRole;
use App\Exceptions\InvalidStateException;
use App\Models\Department;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use App\Services\SubscriptionService;
use Illuminate\Validation\ValidationException;

/**
 * Staff (and customer-account) administration for a tenant.
 *
 * Owns every business rule that used to live inside the HTTP controller:
 * seat capacity, role-escalation limits, department-scoped role/station
 * matching, and facility-assignment authority. Controllers only validate and
 * shape responses, so the App controller can reuse it without copying rules.
 */
class StaffService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Create a tenant user.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Tenant $tenant, User $actor, array $data): User
    {
        $this->validateAssignedOrganisation($tenant, $data);
        $this->guardDepartmentScopedRole($tenant, $data);
        $this->guardFacilityGrants($actor, $data);

        // Only staff roles count against the seat limit; customers do not.
        if ($data['role'] !== UserRole::Customer->value) {
            $this->subscriptions->ensureCapacity($tenant, Feature::MaxStaff);
        }

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'station_id' => $data['station_id'] ?? null,
        ]);

        $user->tenant_id = $tenant->id;
        $user->save();

        $this->syncFacilityAssignments($tenant, $actor, $user, $data['facility_ids'] ?? []);

        return $user->load(['station', 'facilities:id,tenant_id,name,code']);
    }

    /**
     * Update a tenant user.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Tenant $tenant, User $actor, User $user, array $data): User
    {
        $this->validateAssignedOrganisation($tenant, $data, $user);
        $this->guardDepartmentScopedRole($tenant, $data, $user);
        $this->guardFacilityGrants($actor, $data);

        if (array_key_exists('password', $data) && $data['password'] === null) {
            unset($data['password']);
        }

        $user->update($data);

        if (array_key_exists('facility_ids', $data)) {
            $this->syncFacilityAssignments($tenant, $actor, $user, $data['facility_ids'] ?? []);
        }

        return $user->fresh(['station', 'facilities:id,tenant_id,name,code']);
    }

    /**
     * Delete a tenant user, refusing to remove the caller or the last admin.
     */
    public function delete(Tenant $tenant, User $actor, User $user): void
    {
        if ($actor->id === $user->id) {
            throw new InvalidStateException('You cannot delete your own account.');
        }

        if ($user->role === UserRole::TenantAdmin
            && User::query()
                ->where('tenant_id', $tenant->id)
                ->where('role', UserRole::TenantAdmin->value)
                ->count() <= 1) {
            throw new InvalidStateException('Cannot delete the last administrator.');
        }

        $user->delete();
    }

    /**
     * The roles the caller may assign. A facility admin cannot grant tenant
     * admin, facility admin or the platform role.
     *
     * @return list<string>
     */
    public function assignableRoles(User $actor): array
    {
        if ($actor->isTenantAdmin()) {
            return UserRole::tenantValues();
        }

        return [
            UserRole::Operator->value,
            UserRole::Staff->value,
            UserRole::Customer->value,
        ];
    }

    /**
     * Enforce the department-scoped role/station rules.
     *
     * When a caller supplies `department_id` (i.e. manages users from inside a
     * department), only the operational roles belong there and the station must
     * be part of that department and match the role's station type.
     *
     * @param  array<string, mixed>  $data
     */
    public function guardDepartmentScopedRole(Tenant $tenant, array $data, ?User $user = null): void
    {
        if (empty($data['department_id'])) {
            return;
        }

        $departmentId = (int) $data['department_id'];

        $department = Department::query()
            ->whereKey($departmentId)
            ->whereHas('facility', fn($q) => $q->where('tenant_id', $tenant->id))
            ->first();

        if ($department === null) {
            throw ValidationException::withMessages([
                'department_id' => ['Department does not belong to your tenant.'],
            ]);
        }

        $role = (string) ($data['role'] ?? $user?->role?->value ?? '');

        $allowedRoles = [UserRole::Operator->value, UserRole::Staff->value];

        if (! in_array($role, $allowedRoles, true)) {
            throw ValidationException::withMessages([
                'role' => ['Only Operator and Staff may be managed from a department.'],
            ]);
        }

        $stationId = $data['station_id'] ?? null;

        if ($role === UserRole::Operator->value && $stationId === null) {
            throw ValidationException::withMessages([
                'station_id' => ['An operator needs a station.'],
            ]);
        }

        if ($stationId === null) {
            return;
        }

        $allowedTypes = $role === UserRole::Operator->value
            ? [StationType::Intake]
            : [StationType::Service, StationType::Cashier, StationType::Counter];

        $station = Station::query()
            ->whereKey((int) $stationId)
            ->where('department_id', $departmentId)
            ->first();

        if ($station === null) {
            throw ValidationException::withMessages([
                'station_id' => ['Station is not part of this department.'],
            ]);
        }

        if (! in_array($station->type, $allowedTypes, true)) {
            throw ValidationException::withMessages([
                'station_id' => [
                    $role === UserRole::Operator->value
                        ? 'An operator must be assigned to an INTAKE station.'
                        : 'A staff member must be assigned to a SERVICE, CASHIER or COUNTER station.',
                ],
            ]);
        }
    }

    /**
     * Validate every requested facility grant is within the caller's authority.
     *
     * @param  array<string, mixed>  $data
     */
    public function guardFacilityGrants(User $actor, array $data): void
    {
        foreach ((array) ($data['facility_ids'] ?? []) as $facilityId) {
            abort_unless(
                $actor->canAccessFacility((int) $facilityId),
                403,
                'You cannot grant access to a facility you do not administer.',
            );
        }
    }

    /**
     * Add the supplied facility assignments to a user.
     *
     * @param  list<int>  $facilityIds
     */
    public function syncFacilityAssignments(Tenant $tenant, User $actor, User $user, array $facilityIds): void
    {
        abort_if(
            $user->hasTenantWideAccess(),
            422,
            'A tenant admin already has implicit access to every facility.',
        );

        foreach ($facilityIds as $facilityId) {
            UserFacilityAssignment::query()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'facility_id' => (int) $facilityId,
            ], [
                'assigned_by_user_id' => $actor->id,
            ]);
        }
    }

    /**
     * Ensure the assigned station belongs to the tenant.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateAssignedOrganisation(Tenant $tenant, array $data, ?User $user = null): void
    {
        $stationId = $data['station_id'] ?? null;

        if ($stationId === null) {
            return;
        }

        $station = Station::query()
            ->whereKey($stationId)
            ->whereHas('department.facility', fn($query) => $query->where('tenant_id', $tenant->id))
            ->first();

        if (! $station) {
            throw ValidationException::withMessages([
                'station_id' => ['Station does not belong to your tenant.'],
            ]);
        }
    }
}
