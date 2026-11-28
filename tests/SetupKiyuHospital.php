<?php

namespace Tests;

use App\Enums\StationType;
use App\Enums\TenantStatus;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Facility;
use App\Models\KioskDevice;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use App\Models\Workflow;
use App\Models\WorkflowStep;

/**
 * A minimal but realistic tenant: one facility, two departments, a workflow
 * with several steps, a kiosk device, and a tenant admin.
 */
trait SetupKiyuHospital
{
    protected Tenant $tenant;

    protected Facility $facility;

    protected string $kioskToken;

    protected Department $generalDepartment;

    protected Station $registrationStation;

    protected Station $serviceStation1;

    protected Station $serviceStation2;

    protected Station $cashierStation;

    protected Workflow $generalWorkflow;

    protected WorkflowStep $regStep;

    protected WorkflowStep $serviceStep1;

    protected WorkflowStep $serviceStep2;

    protected WorkflowStep $cashierStep;

    protected Department $supportDepartment;

    protected Station $supportStation;

    protected Workflow $supportWorkflow;

    protected WorkflowStep $supportStep;

    protected Customer $sampleCustomer;

    protected User $adminUser;

    protected User $staffUser;

    protected User $tenantAdminUser;

    protected function setupHospital(): void
    {
        $this->tenant = Tenant::query()->create([
            'name' => 'KIYU Demo Organization',
            'slug' => 'kiyu-demo',
            'status' => TenantStatus::Active,
        ]);

        $this->facility = Facility::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'KIYU Demo Facility',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $this->generalDepartment = Department::create([
            'facility_id' => $this->facility->id,
            'code' => 'GEN',
            'name' => 'General Service',
            'is_active' => true,
        ]);

        $this->registrationStation = Station::create([
            'department_id' => $this->generalDepartment->id,
            'name' => 'Intake Counter 1',
            'code' => 'REG-01',
            'type' => StationType::Intake,
            'queue_prefix' => 'R',
            'is_active' => true,
        ]);

        $this->serviceStation1 = Station::create([
            'department_id' => $this->generalDepartment->id,
            'name' => 'Service Counter 1',
            'code' => 'SVC-01',
            'type' => StationType::Service,
            'queue_prefix' => 'N',
            'is_active' => true,
        ]);

        $this->serviceStation2 = Station::create([
            'department_id' => $this->generalDepartment->id,
            'name' => 'Service Counter 2',
            'code' => 'SVC-02',
            'type' => StationType::Service,
            'queue_prefix' => 'D',
            'is_active' => true,
        ]);

        $this->cashierStation = Station::create([
            'department_id' => $this->generalDepartment->id,
            'name' => 'Cashier',
            'code' => 'CSH-01',
            'type' => StationType::Cashier,
            'queue_prefix' => 'K',
            'is_active' => true,
        ]);

        $this->generalWorkflow = Workflow::create([
            'department_id' => $this->generalDepartment->id,
            'name' => 'General Flow',
            'is_active' => true,
        ]);

        $this->regStep = WorkflowStep::create([
            'workflow_id' => $this->generalWorkflow->id,
            'station_id' => $this->registrationStation->id,
            'name' => 'Intake',
            'sequence' => 1,
            'is_active' => true,
        ]);

        $this->serviceStep1 = WorkflowStep::create([
            'workflow_id' => $this->generalWorkflow->id,
            'station_id' => $this->serviceStation1->id,
            'name' => 'Service',
            'sequence' => 2,
            'is_active' => true,
        ]);

        $this->serviceStep2 = WorkflowStep::create([
            'workflow_id' => $this->generalWorkflow->id,
            'station_id' => $this->serviceStation2->id,
            'name' => 'Follow-up',
            'sequence' => 3,
            'is_active' => true,
        ]);

        $this->cashierStep = WorkflowStep::create([
            'workflow_id' => $this->generalWorkflow->id,
            'station_id' => $this->cashierStation->id,
            'name' => 'Payment',
            'sequence' => 4,
            'is_active' => true,
        ]);

        // A second department, used by the service-transfer tests.
        $this->supportDepartment = Department::create([
            'facility_id' => $this->facility->id,
            'code' => 'SUP',
            'name' => 'Support Service',
            'is_active' => true,
        ]);

        $supportIntakeStation = Station::create([
            'department_id' => $this->supportDepartment->id,
            'name' => 'Support Intake',
            'code' => 'REG-SUP',
            'type' => StationType::Intake,
            'queue_prefix' => 'S',
            'is_active' => true,
        ]);

        $this->supportStation = Station::create([
            'department_id' => $this->supportDepartment->id,
            'name' => 'Support Counter',
            'code' => 'SUP-01',
            'type' => StationType::Service,
            'queue_prefix' => 'G',
            'is_active' => true,
        ]);

        $this->supportWorkflow = Workflow::create([
            'department_id' => $this->supportDepartment->id,
            'name' => 'Support Flow',
            'is_active' => true,
        ]);

        WorkflowStep::create([
            'workflow_id' => $this->supportWorkflow->id,
            'station_id' => $supportIntakeStation->id,
            'name' => 'Support Intake',
            'sequence' => 1,
            'is_active' => true,
        ]);

        $this->supportStep = WorkflowStep::create([
            'workflow_id' => $this->supportWorkflow->id,
            'station_id' => $this->supportStation->id,
            'name' => 'Support Service',
            'sequence' => 2,
            'is_active' => true,
        ]);

        $this->sampleCustomer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'email' => 'budi@example.com',
            'address' => 'Jl. Merdeka No. 10',
        ]);

        // Register the kiosk device used by the public intake tests.
        $this->kioskToken = 'test-kiosk-token';

        KioskDevice::query()->create([
            'tenant_id' => $this->tenant->id,
            'facility_id' => $this->facility->id,
            'name' => 'Test Kiosk',
            'credential_hash' => hash('sha256', $this->kioskToken),
            'paired_at' => now(),
            'is_active' => true,
        ]);

        // Every request presents the device credential and the public tenant
        // locator, mirroring the deployed kiosk client. The tenant Origin binds
        // an authenticated session to this tenant.
        $this->withHeaders([
            'X-Kiosk-Token' => $this->kioskToken,
            'X-Tenant-Slug' => $this->tenant->slug,
            'Origin' => $this->tenantOrigin($this->tenant->slug),
        ]);

        // Authenticate a facility admin for protected workspace routes.
        // Exposed so tests can assert the actor recorded on queue operations.
        $this->adminUser = User::factory()->create([
            'role' => 'facility_admin',
            'tenant_id' => $this->tenant->id,
        ]);

        UserFacilityAssignment::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminUser->id,
            'facility_id' => $this->facility->id,
        ]);

        // Service Transfer and ticket reassignment are Staff-only (PRD §14.5).
        $this->staffUser = User::factory()->create([
            'role' => 'staff',
            'tenant_id' => $this->tenant->id,
        ]);

        // A TenantAdmin for the App (organization) context.
        $this->tenantAdminUser = User::factory()->create([
            'role' => 'tenant_admin',
            'tenant_id' => $this->tenant->id,
        ]);

        UserFacilityAssignment::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->staffUser->id,
            'facility_id' => $this->facility->id,
        ]);

        $this->actingAs($this->adminUser, 'sanctum');
    }

    /**
     * Authenticate the fixture's staff account for Staff-only operations.
     */
    protected function actAsStaff(): static
    {
        $this->actingAs($this->staffUser, 'sanctum');

        return $this;
    }

    /**
     * Authenticate the fixture's TenantAdmin for App-context operations.
     */
    protected function actAsTenantAdmin(): static
    {
        $this->actingAs($this->tenantAdminUser, 'sanctum')->asContext('app');

        return $this;
    }

    /**
     * The tenant Origin for a slug, matching the configured suffix.
     */
    protected function tenantOrigin(string $slug): string
    {
        $scheme = parse_url((string) config('kiyu.origins.app'), PHP_URL_SCHEME) ?: 'http';
        $suffix = (string) config('kiyu.tenant_domain_suffix', 'kiyu.test');

        return "{$scheme}://{$slug}.{$suffix}";
    }
}
