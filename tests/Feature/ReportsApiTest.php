<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\IntakeChannel;
use App\Enums\Priority;
use App\Enums\TenantStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visit;
use App\Models\Workflow;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, facility: Facility, admin: User, department: Department, workflow: Workflow, plan: Plan}
 */
function reportClinic(bool $advanced = false): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Report Clinic',
        'slug' => 'report-clinic-' . Str::random(6),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Report Facility',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);

    $department = Department::create([
        'facility_id' => $facility->id,
        'code' => 'GEN',
        'name' => 'General',
        'is_active' => true,
    ]);

    $workflow = Workflow::create([
        'department_id' => $department->id,
        'name' => 'Flow',
        'is_active' => true,
    ]);

    $plan = Plan::query()->create(['code' => 'P-' . Str::random(6), 'name' => 'P', 'is_active' => true]);
    $plan->entitlements()->create(['feature_code' => Feature::BasicReports->value, 'value' => null]);

    if ($advanced) {
        $plan->entitlements()->create(['feature_code' => Feature::AdvancedReports->value, 'value' => null]);
    }

    app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);

    return compact('tenant', 'facility', 'admin', 'department', 'workflow', 'plan');
}

/**
 * @param  array<string, mixed>  $clinic
 */
function reportVisit(array $clinic, string $status = 'COMPLETED'): Visit
{
    $client = Client::query()->create([
        'tenant_id' => $clinic['tenant']->id,
        'mrn' => 'MRN-' . Str::random(6),
        'name' => 'Client',
    ]);

    return Visit::query()->create([
        'tenant_id' => $clinic['tenant']->id,
        'facility_id' => $clinic['facility']->id,
        'client_id' => $client->id,
        'department_id' => $clinic['department']->id,
        'workflow_id' => $clinic['workflow']->id,
        'visit_number' => 'V-' . Str::random(8),
        'visit_token' => (string) Str::uuid(),
        'priority' => Priority::Normal,
        'intake_channel' => IntakeChannel::Manual,
        'status' => $status,
        'checked_in_at' => now(),
    ]);
}

test('report summary returns visit and queue totals', function () {
    $clinic = reportClinic();
    reportVisit($clinic, 'COMPLETED');
    reportVisit($clinic, 'WAITING');

    $this->actingAs($clinic['admin'], 'sanctum')
        ->getJson('/api/reports/summary')
        ->assertOk()
        ->assertJsonPath('data.visits.total_visits', 2)
        ->assertJsonPath('data.visits.completed_visits', 1)
        ->assertJsonStructure(['data' => ['visits', 'queue']]);
});

test('report summary requires the basic_reports feature', function () {
    $tenant = Tenant::query()->create(['name' => 'No Plan', 'slug' => 'no-plan', 'status' => TenantStatus::Active]);
    $facility = Facility::query()->create(['tenant_id' => $tenant->id, 'name' => 'F', 'code' => 'MAIN', 'is_active' => true]);
    $admin = User::factory()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/reports/summary')
        ->assertForbidden();
});

test('csv export is gated by the advanced_reports feature', function () {
    $basic = reportClinic(advanced: false);

    $this->actingAs($basic['admin'], 'sanctum')
        ->getJson('/api/reports/visits/export')
        ->assertForbidden();

    $advanced = reportClinic(advanced: true);
    reportVisit($advanced);

    $response = $this->actingAs($advanced['admin'], 'sanctum')
        ->get('/api/reports/visits/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});
