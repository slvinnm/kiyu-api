<?php

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A tenant with two active facilities, an owner, and an operator assigned to
 * only one of them.
 *
 * @return array{tenant: Tenant, facility: Facility, otherFacility: Facility, owner: User, operator: User}
 */
function facilityContextFixture(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Facility Context Org',
        'slug' => 'facility-context-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $otherFacility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Branch',
        'code' => 'BRANCH',
        'is_active' => true,
    ]);

    $owner = User::factory()->create([
        'role' => UserRole::TenantAdmin,
        'tenant_id' => $tenant->id,
    ]);

    $tenant->forceFill(['owner_user_id' => $owner->id])->save();

    // A facility admin assigned to both facilities, for Tenant-workspace
    // facility selection (tenant_admin now manages from the App context).
    $supervisor = User::factory()->create([
        'role' => UserRole::FacilityAdmin,
        'tenant_id' => $tenant->id,
    ]);

    foreach ([$facility, $otherFacility] as $f) {
        UserFacilityAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $supervisor->id,
            'facility_id' => $f->id,
        ]);
    }

    $operator = User::factory()->create([
        'role' => UserRole::Operator,
        'tenant_id' => $tenant->id,
    ]);

    UserFacilityAssignment::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $operator->id,
        'facility_id' => $facility->id,
    ]);

    UserFacilityAssignment::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $operator->id,
        'facility_id' => $otherFacility->id,
    ]);

    Department::query()->create([
        'tenant_id' => $tenant->id,
        'facility_id' => $facility->id,
        'code' => 'MAIN-A',
        'name' => 'Main Desk',
        'is_active' => true,
        'display_in_kiosk' => true,
    ]);

    Department::query()->create([
        'tenant_id' => $tenant->id,
        'facility_id' => $otherFacility->id,
        'code' => 'BRANCH-A',
        'name' => 'Branch Desk',
        'is_active' => true,
        'display_in_kiosk' => true,
    ]);

    return compact('tenant', 'facility', 'otherFacility', 'owner', 'supervisor', 'operator');
}

test('a multi-facility operator must name a facility with X-Facility-Id', function () {
    ['operator' => $operator] = facilityContextFixture();

    $this->actingAs($operator, 'sanctum')
        ->getJson('/api/tenant/departments')
        ->assertStatus(400)
        ->assertJsonPath('message', 'This tenant has several facilities. Send X-Facility-Id to choose one.');
});

test('an operator sees only the departments of the facility they select', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'otherFacility' => $otherFacility, 'operator' => $operator] =
        facilityContextFixture();

    $own = $this->actingAs($operator, 'sanctum')
        ->getJson('/api/tenant/departments/options', ['X-Facility-Id' => (string) $facility->id])
        ->assertOk()
        ->json('data');

    expect(collect($own)->pluck('code')->all())->toBe(['MAIN-A']);

    // A third facility the operator is not assigned to is refused, not
    // silently swapped for one they can reach.
    $unassigned = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Unassigned',
        'code' => 'UN',
        'is_active' => true,
    ]);

    $this->actingAs($operator, 'sanctum')
        ->getJson('/api/tenant/departments/options', ['X-Facility-Id' => (string) $unassigned->id])
        ->assertForbidden();
});

test('a facility admin may select any facility it is assigned to', function () {
    ['facility' => $facility, 'otherFacility' => $otherFacility, 'supervisor' => $supervisor] =
        facilityContextFixture();

    foreach ([$facility, $otherFacility] as $target) {
        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/tenant/departments/options', ['X-Facility-Id' => (string) $target->id])
            ->assertOk();
    }
});

test('a facility id from another tenant is not found', function () {
    ['operator' => $operator] = facilityContextFixture();

    $foreignTenant = Tenant::query()->create([
        'name' => 'Foreign Org',
        'slug' => 'foreign-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $foreignFacility = Facility::query()->create([
        'tenant_id' => $foreignTenant->id,
        'name' => 'Foreign',
        'code' => 'FGN',
        'is_active' => true,
    ]);

    $this->actingAs($operator, 'sanctum')
        ->getJson('/api/tenant/departments/options', ['X-Facility-Id' => (string) $foreignFacility->id])
        ->assertNotFound();
});

test('a malformed X-Facility-Id is a bad request', function () {
    ['operator' => $operator] = facilityContextFixture();

    foreach (['abc', '-1', '1.5'] as $value) {
        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/tenant/departments/options', ['X-Facility-Id' => $value])
            ->assertStatus(400);
    }
});

test('an inactive facility cannot be selected', function () {
    ['otherFacility' => $otherFacility, 'supervisor' => $supervisor] = facilityContextFixture();

    $otherFacility->update(['is_active' => false]);

    $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/tenant/departments/options', ['X-Facility-Id' => (string) $otherFacility->id])
        ->assertNotFound();
});

test('a user with no reachable facility is forbidden rather than asked for a header', function () {
    ['tenant' => $tenant] = facilityContextFixture();

    $orphan = User::factory()->create([
        'role' => UserRole::Operator,
        'tenant_id' => $tenant->id,
    ]);

    $this->actingAs($orphan, 'sanctum')
        ->getJson('/api/tenant/departments')
        ->assertForbidden();
});

test('a single-facility tenant needs no header at all', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Single Org',
        'slug' => 'single-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Only',
        'code' => 'ONLY',
        'is_active' => true,
    ]);

    $owner = User::factory()->create([
        'role' => UserRole::TenantAdmin,
        'tenant_id' => $tenant->id,
    ]);

    $tenant->forceFill(['owner_user_id' => $owner->id])->save();

    // A facility admin assigned to the only facility (tenant_admin manages from
    // the App context now).
    $supervisor = User::factory()->create([
        'role' => UserRole::FacilityAdmin,
        'tenant_id' => $tenant->id,
    ]);

    UserFacilityAssignment::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $supervisor->id,
        'facility_id' => $facility->id,
    ]);

    Department::query()->create([
        'tenant_id' => $tenant->id,
        'facility_id' => $facility->id,
        'code' => 'ONLY-A',
        'name' => 'Only Desk',
        'is_active' => true,
        'display_in_kiosk' => true,
    ]);

    $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/tenant/departments/options')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('device creation accepts an explicit facility the caller administers', function () {
    ['facility' => $facility, 'otherFacility' => $otherFacility, 'supervisor' => $supervisor] =
        facilityContextFixture();

    $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', [
            'name' => 'Branch Kiosk',
            'facility_id' => $otherFacility->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.device.facility_id', $otherFacility->id);

    $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', [
            'name' => 'Main Kiosk',
            'facility_id' => $facility->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.device.facility_id', $facility->id);
});

test('a multi-facility tenant must name the device facility explicitly', function () {
    ['supervisor' => $supervisor] = facilityContextFixture();

    $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Homeless Kiosk'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('facility_id');
});

test('device creation refuses a facility the operator is not assigned to', function () {
    ['otherFacility' => $otherFacility, 'operator' => $operator] = facilityContextFixture();

    $this->actingAs($operator, 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', [
            'name' => 'Sneaky Kiosk',
            'facility_id' => $otherFacility->id,
        ])
        ->assertForbidden();
});
