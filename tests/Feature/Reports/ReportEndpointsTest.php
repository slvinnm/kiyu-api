<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use App\Services\Access\EntitlementResolver;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, facility: Facility, admin: User}
 */
function reportEndpointClinic(string $defaultRole = 'tenant_admin'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Report Endpoint Clinic',
        'slug' => 'rep-ep-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    Department::create([
        'facility_id' => $facility->id,
        'code' => 'GEN',
        'name' => 'General',
        'is_active' => true,
    ]);

    $admin = User::factory()->create(['role' => $defaultRole, 'tenant_id' => $tenant->id]);

    $plan = Plan::query()->create(['code' => 'R-' . uniqid(), 'name' => 'R', 'is_active' => true]);
    $plan->entitlements()->create(['feature_code' => Feature::QueueManagement->value, 'value' => null]);

    $subscription = app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);

    return compact('tenant', 'facility', 'admin', 'plan', 'subscription');
}

/**
 * Grant a feature to the tenant's live snapshot (as activation would), then drop
 * the resolved-entitlement cache.
 */
function grantSnapshot(array $c, Feature $feature): void
{
    $c['subscription']->entitlements()->updateOrCreate(
        ['feature_code' => $feature->value],
        ['value' => null],
    );
    app(EntitlementResolver::class)->forget($c['tenant']);
}

test('each report has its own endpoint and entitlement gate', function () {
    $c = reportEndpointClinic();

    // The plan currently grants no report codes: every report is forbidden.
    foreach ([
        'queue-overview',
        'daily-queue',
        'visit-details',
        'department-performance',
        'waiting-time',
        'service-duration',
        'queue-trends',
        'peak-hours',
        'cancellation-no-show',
        'staff-performance',
        'facility-comparison',
    ] as $path) {
        $this->actingAs($c['admin'], 'sanctum')->asContext('app')
            ->getJson("/api/app/reports/{$path}")
            ->assertForbidden();
    }

    // Grant one report to the snapshot; only that endpoint opens.
    grantSnapshot($c, Feature::ReportPeakHours);

    $this->actingAs($c['admin'], 'sanctum')->asContext('app')
        ->getJson('/api/app/reports/peak-hours')
        ->assertOk()
        ->assertJsonStructure(['data' => ['period', 'hours']]);

    $this->actingAs($c['admin'], 'sanctum')->asContext('app')
        ->getJson('/api/app/reports/queue-trends')
        ->assertForbidden();
});

test('export requires both the report and report_export', function () {
    $c = reportEndpointClinic();

    grantSnapshot($c, Feature::ReportQueueOverview);

    // Report but no export → export forbidden.
    $this->actingAs($c['admin'], 'sanctum')->asContext('app')
        ->get('/api/app/reports/queue-sessions/export')
        ->assertForbidden();

    grantSnapshot($c, Feature::ReportExport);

    $this->actingAs($c['admin'], 'sanctum')->asContext('app')
        ->get('/api/app/reports/queue-sessions/export')
        ->assertOk();
});

test('the tenant workspace exposes the same per-report endpoints', function () {
    $c = reportEndpointClinic(defaultRole: 'facility_admin');
    UserFacilityAssignment::query()->create([
        'tenant_id' => $c['tenant']->id,
        'user_id' => $c['admin']->id,
        'facility_id' => $c['facility']->id,
    ]);

    grantSnapshot($c, Feature::ReportDailyQueue);

    $this->actingAs($c['admin'], 'sanctum')
        ->getJson('/api/tenant/reports/daily-queue?facility_id=' . $c['facility']->id)
        ->assertOk()
        ->assertJsonStructure(['data' => ['period', 'days']]);
});
