<?php

namespace App\Services;

use App\Enums\StationType;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessConflictException;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provisions a new tenant.
 *
 * A tenant is never usable until it has a facility, an admin who can operate
 * it, and at least one department/station/workflow so the queue can run. All
 * of that is created in one transaction so a partial tenant cannot exist.
 */
class TenantOnboardingService
{
    /**
     * Provision a tenant, its first facility, its admin, and the default
     * queue template.
     *
     * @param  array{
     *     name: string,
     *     slug?: string|null,
     *     timezone?: string|null,
     *     billing_email?: string|null,
     *     contact_name?: string|null,
     *     contact_email?: string|null,
     *     contact_phone?: string|null,
     *     facility_name: string,
     *     facility_code?: string,
     *     facility_address?: string|null,
     *     facility_phone?: string|null,
     *     admin_name: string,
     *     admin_email: string,
     *     admin_password: string,
     *     department_code?: string,
     *     department_name?: string,
     * }  $data
     * @return array{tenant: Tenant, facility: Facility, admin: User}
     */
    public function provision(array $data): array
    {
        $slug = $data['slug'] ?? Str::slug($data['name']);

        if ($slug === '') {
            throw new BusinessConflictException('A tenant slug could not be derived from the organization name.');
        }

        if (Tenant::query()->where('slug', $slug)->exists()) {
            throw new BusinessConflictException("Tenant slug '{$slug}' is already taken.");
        }

        if (User::query()->where('email', $data['admin_email'])->exists()) {
            throw new BusinessConflictException("Email '{$data['admin_email']}' is already registered.");
        }

        return DB::transaction(function () use ($data, $slug): array {
            $tenant = Tenant::query()->create([
                'name' => $data['name'],
                'slug' => $slug,
                'status' => TenantStatus::Active,
                // IANA identifier; the caller supplies it. UTC is the neutral
                // fallback, never a country-specific zone.
                'timezone' => $data['timezone'] ?? 'UTC',
                'billing_email' => $data['billing_email'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
            ]);

            $facility = $this->createFacility($tenant, $data);

            // Ownership is set explicitly: tenant_id is not mass-assignable on
            // User.
            $admin = new User([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'role' => UserRole::TenantAdmin,
            ]);
            $admin->tenant_id = $tenant->id;
            $admin->save();

            // Cycle the owner reference now that the user exists, inside this
            // same transaction, so no active tenant is left ownerless.
            $tenant->forceFill(['owner_user_id' => $admin->id])->save();

            $this->createDefaultTemplate($facility, $data);

            return [
                'tenant' => $tenant,
                'facility' => $facility,
                'tenant_admin' => $admin,
            ];
        });
    }

    /**
     * Create the facility owned by the tenant.
     *
     * @param  array<string, mixed>  $data
     */
    private function createFacility(Tenant $tenant, array $data): Facility
    {
        return Facility::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $data['facility_name'],
            'code' => $data['facility_code'] ?? 'MAIN',
            'address' => $data['facility_address'] ?? null,
            'phone' => $data['facility_phone'] ?? null,
            'timezone' => null,
            'is_active' => true,
        ]);
    }

    /**
     * Seed a minimal but operational queue template: one department, a
     * registration station and a single-step workflow.
     *
     * This is a starting point the tenant admin edits afterwards; it is owned
     * by the tenant's facility, never global.
     *
     * @param  array<string, mixed>  $data
     */
    private function createDefaultTemplate(Facility $facility, array $data): void
    {
        $department = Department::query()->create([
            'facility_id' => $facility->id,
            'code' => $data['department_code'] ?? 'GEN',
            'name' => $data['department_name'] ?? 'General',
            'is_active' => true,
            'display_in_kiosk' => true,
        ]);

        $station = Station::query()->create([
            'department_id' => $department->id,
            'name' => 'Registration',
            'code' => 'REG-01',
            'type' => StationType::Intake,
            'queue_prefix' => 'R',
            'is_active' => true,
        ]);

        $workflow = Workflow::query()->create([
            'department_id' => $department->id,
            'name' => 'Registration Flow',
            'is_active' => true,
        ]);

        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'station_id' => $station->id,
            'name' => 'Registration',
            'sequence' => 1,
            'is_active' => true,
        ]);
    }
}
