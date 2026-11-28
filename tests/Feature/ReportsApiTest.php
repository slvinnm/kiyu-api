<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\IntakeChannel;
use App\Enums\Priority;
use App\Enums\TenantStatus;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Plan;
use App\Models\QueueSession;
use App\Models\Tenant;
use App\Models\User;
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

    $admin = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => $tenant->id]);

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
    $plan->entitlements()->create(['feature_code' => Feature::ReportQueueOverview->value, 'value' => null]);

    if ($advanced) {
        $plan->entitlements()->create(['feature_code' => Feature::ReportExport->value, 'value' => null]);
    }

    app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);

    return compact('tenant', 'facility', 'admin', 'department', 'workflow', 'plan');
}

/**
 * @param  array<string, mixed>  $tenant
 */
function reportVisit(array $tenant, string $status = 'COMPLETED'): QueueSession
{
    $client = Customer::query()->create([
        'tenant_id' => $tenant['tenant']->id,
        'phone' => '0812' . Str::random(6),
        'name' => 'Client',
    ]);

    return QueueSession::query()->create([
        'tenant_id' => $tenant['tenant']->id,
        'facility_id' => $tenant['facility']->id,
        'customer_id' => $client->id,
        'department_id' => $tenant['department']->id,
        'workflow_id' => $tenant['workflow']->id,
        'session_number' => 'V-' . Str::random(8),
        'tracking_token' => (string) Str::uuid(),
        'priority' => Priority::Normal,
        'intake_channel' => IntakeChannel::Manual,
        'status' => $status,
        'checked_in_at' => now(),
    ]);
}

test('report summary returns session and queue totals', function () {
    $tenant = reportClinic();
    reportVisit($tenant, 'COMPLETED');
    reportVisit($tenant, 'WAITING');

    $this->actingAs($tenant['admin'], 'sanctum')
        ->getJson('/api/app/reports/summary')
        ->assertOk()
        ->assertJsonPath('data.queue_sessions.total_sessions', 2)
        ->assertJsonPath('data.queue_sessions.completed_sessions', 1)
        ->assertJsonStructure(['data' => ['queue_sessions', 'queue']]);
});

test('report summary requires the basic_reports feature', function () {
    $tenant = Tenant::query()->create(['name' => 'No Plan', 'slug' => 'no-plan', 'status' => TenantStatus::Active]);
    $facility = Facility::query()->create(['tenant_id' => $tenant->id, 'name' => 'F', 'code' => 'MAIN', 'is_active' => true]);
    $admin = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => $tenant->id]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/app/reports/summary')
        ->assertForbidden();
});

test('csv export is gated by the advanced_reports feature', function () {
    $basic = reportClinic(advanced: false);

    $this->actingAs($basic['admin'], 'sanctum')
        ->getJson('/api/app/reports/queue-sessions/export')
        ->assertForbidden();

    $advanced = reportClinic(advanced: true);
    reportVisit($advanced);

    $response = $this->actingAs($advanced['admin'], 'sanctum')
        ->get('/api/app/reports/queue-sessions/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});
