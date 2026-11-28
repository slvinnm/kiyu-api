<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Models\Facility;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @param  list<Feature>  $toggles
 * @return array{tenant: Tenant, facility: Facility, admin: User, manager: User}
 */
function gateClinic(array $toggles, string $role = 'facility_admin'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Gate Clinic',
        'slug' => 'gate-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create(['role' => $role, 'tenant_id' => $tenant->id]);

    // Facility-scoped roles need an assignment to act in the workspace.
    if (in_array($role, ['facility_admin', 'operator', 'staff'], true)) {
        UserFacilityAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'facility_id' => $facility->id,
        ]);
    }

    $plan = Plan::query()->create(['code' => 'G-' . uniqid(), 'name' => 'G', 'is_active' => true]);

    foreach ($toggles as $feature) {
        $plan->entitlements()->create(['feature_code' => $feature->value, 'value' => null]);
    }

    app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);

    return compact('tenant', 'facility', 'admin');
}

test('reports summary requires the basic_reports toggle, present or absent', function () {
    $without = gateClinic([]);
    $this->actingAs($without['admin'], 'sanctum')
        ->getJson('/api/tenant/reports/summary')
        ->assertForbidden();

    $with = gateClinic([Feature::ReportQueueOverview]);
    $this->actingAs($with['admin'], 'sanctum')
        ->getJson('/api/tenant/reports/summary')
        ->assertOk();
});

test('csv export requires advanced_reports, which basic_reports alone does not grant', function () {
    $basic = gateClinic([Feature::ReportQueueOverview]);
    $this->actingAs($basic['admin'], 'sanctum')
        ->get('/api/tenant/reports/queue-sessions/export')
        ->assertForbidden();

    $advanced = gateClinic([Feature::ReportQueueOverview, Feature::ReportExport]);
    $this->actingAs($advanced['admin'], 'sanctum')
        ->get('/api/tenant/reports/queue-sessions/export')
        ->assertOk();
});

test('whatsapp sending requires the whatsapp_notifications toggle', function () {
    Http::fake();

    $without = gateClinic([]);
    $this->actingAs($without['admin'], 'sanctum')
        ->postJson('/api/tenant/whatsapp/messages', ['to' => '628', 'type' => 'text', 'body' => 'hi'])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('an unknown feature code resolves to null (middleware turns it into a 500)', function () {
    expect(Feature::tryFrom('does_not_exist'))->toBeNull();
});

test('a role not permitted for an endpoint is refused even with the feature', function () {
    $staff = gateClinic([Feature::ReportQueueOverview], role: 'staff');

    // reports summary allows admin + receptionist only; staff is refused.
    $this->actingAs($staff['admin'], 'sanctum')
        ->getJson('/api/tenant/reports/summary')
        ->assertForbidden();
});

test('a suspended tenant is refused before any feature check', function () {
    $tenant = gateClinic([Feature::ReportQueueOverview]);
    $tenant['tenant']->update(['status' => TenantStatus::Suspended]);

    $this->actingAs($tenant['admin'], 'sanctum')
        ->getJson('/api/tenant/reports/summary')
        ->assertForbidden();
});

test('an expired trial grants no features', function () {
    $tenant = gateClinic([Feature::ReportQueueOverview]);

    $subscription = $tenant['tenant']->subscriptions()->latest('id')->firstOrFail();
    $subscription->update(['trial_ends_at' => now()->subDay()]);

    $this->actingAs($tenant['admin'], 'sanctum')
        ->getJson('/api/tenant/reports/summary')
        ->assertForbidden();
});
