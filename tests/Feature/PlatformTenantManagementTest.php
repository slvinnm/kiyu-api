<?php

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\KioskDevice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, facility: Facility, admin: User}
 */
function managedClinic(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Managed Clinic',
        'slug' => 'managed-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);

    return compact('tenant', 'facility', 'admin');
}

function platformOperator(): User
{
    return User::factory()->create([
        'role' => UserRole::PlatformAdmin,
        'tenant_id' => null,
    ]);
}

test('a platform operator can list a tenant staff, clients, kiosk devices and facilities', function () {
    $clinic = managedClinic();
    $operator = platformOperator();

    KioskDevice::query()->create([
        'tenant_id' => $clinic['tenant']->id,
        'facility_id' => $clinic['facility']->id,
        'name' => 'Kiosk',
        'credential_hash' => hash('sha256', 'token'),
        'is_active' => true,
    ]);

    $base = "/api/platform/tenants/{$clinic['tenant']->id}/manage";

    $this->actingAs($operator, 'sanctum')->getJson("{$base}/users")->assertOk();
    $this->actingAs($operator, 'sanctum')->getJson("{$base}/clients")->assertOk();
    $this->actingAs($operator, 'sanctum')->getJson("{$base}/facilities")
        ->assertOk()->assertJsonPath('data.0.code', 'MAIN');
    $this->actingAs($operator, 'sanctum')->getJson("{$base}/kiosk-devices")
        ->assertOk()->assertJsonCount(1, 'data');
});

test('a platform operator can create staff in the managed tenant', function () {
    $clinic = managedClinic();
    $operator = platformOperator();

    $this->actingAs($operator, 'sanctum')
        ->postJson("/api/platform/tenants/{$clinic['tenant']->id}/manage/users", [
            'name' => 'Support Added',
            'email' => 'support-added@managed.test',
            'password' => 'password123',
            'role' => 'staff',
        ])
        ->assertCreated()
        ->assertJsonPath('data.tenant_id', $clinic['tenant']->id);
});

test('a platform operator can delete a kiosk device and a staff user', function () {
    $clinic = managedClinic();
    $operator = platformOperator();

    $device = KioskDevice::query()->create([
        'tenant_id' => $clinic['tenant']->id,
        'facility_id' => $clinic['facility']->id,
        'name' => 'Kiosk',
        'credential_hash' => hash('sha256', 'token'),
        'is_active' => true,
    ]);
    $staff = User::factory()->create(['role' => 'staff', 'tenant_id' => $clinic['tenant']->id]);

    $base = "/api/platform/tenants/{$clinic['tenant']->id}/manage";

    $this->actingAs($operator, 'sanctum')
        ->deleteJson("{$base}/kiosk-devices/{$device->id}")
        ->assertOk();

    expect($device->fresh()->is_active)->toBeFalse();

    $this->actingAs($operator, 'sanctum')
        ->deleteJson("{$base}/users/{$staff->id}")
        ->assertOk();

    $this->assertDatabaseMissing('users', ['id' => $staff->id]);
});

test('a platform operator only ever touches the tenant named in the route', function () {
    $a = managedClinic();
    $b = managedClinic();
    $operator = platformOperator();

    // The target tenant has no staff besides its admin; the other tenant does.
    User::factory()->create(['role' => 'staff', 'tenant_id' => $b['tenant']->id]);

    $response = $this->actingAs($operator, 'sanctum')
        ->getJson("/api/platform/tenants/{$a['tenant']->id}/manage/users")
        ->assertOk();

    // Only tenant A's own users are present, never tenant B's.
    $ids = collect($response->json('data.data'))->pluck('tenant_id')->unique();
    expect($ids->all())->toBe([$a['tenant']->id]);
});

test('an unknown tenant id is a 404', function () {
    $operator = platformOperator();

    $this->actingAs($operator, 'sanctum')
        ->getJson('/api/platform/tenants/999999/manage/users')
        ->assertNotFound();
});

test('a tenant admin cannot use the platform manage routes', function () {
    $clinic = managedClinic();

    $this->actingAs($clinic['admin'], 'sanctum')
        ->getJson("/api/platform/tenants/{$clinic['tenant']->id}/manage/users")
        ->assertForbidden();
});

test('a platform operator cannot use normal tenant routes', function () {
    $operator = platformOperator();

    $this->actingAs($operator, 'sanctum')
        ->getJson('/api/users')
        ->assertForbidden();
});

test('managing a suspended tenant is still allowed for support', function () {
    $clinic = managedClinic();
    $clinic['tenant']->update(['status' => TenantStatus::Suspended]);
    $operator = platformOperator();

    $this->actingAs($operator, 'sanctum')
        ->getJson("/api/platform/tenants/{$clinic['tenant']->id}/manage/users")
        ->assertOk();
});
