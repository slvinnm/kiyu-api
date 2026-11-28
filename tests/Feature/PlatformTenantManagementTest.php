<?php

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * PlatformAdmin no longer impersonates a tenant. These tests assert the
 * impersonation routes are gone and that platform isolation still holds.
 *
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

    $admin = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => $tenant->id]);

    return compact('tenant', 'facility', 'admin');
}

function platformOperator(): User
{
    return User::factory()->create([
        'role' => UserRole::PlatformAdmin,
        'tenant_id' => null,
    ]);
}

test('the platform tenant impersonation routes are removed', function () {
    $tenant = managedClinic();
    $operator = platformOperator();

    $base = "/api/portal/tenants/{$tenant['tenant']->id}/manage";

    foreach (['users', 'customers', 'facilities', 'kiosk-devices'] as $segment) {
        $this->asContext('portal')
            ->actingAs($operator, 'sanctum')
            ->getJson("{$base}/{$segment}")
            ->assertNotFound();
    }

    $this->actingAs($operator, 'sanctum')
        ->getJson("/api/platform/tenants/{$tenant['tenant']->id}/manage/users")
        ->assertNotFound();
});

test('a platform operator cannot create tenant staff or devices', function () {
    $tenant = managedClinic();
    $operator = platformOperator();

    $this->asContext('portal')
        ->actingAs($operator, 'sanctum')
        ->postJson("/api/portal/tenants/{$tenant['tenant']->id}/manage/users", [
            'name' => 'Support Added',
            'email' => 'support-added@managed.test',
            'password' => 'password123',
            'role' => 'staff',
        ])
        ->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => 'support-added@managed.test']);
});

test('a platform operator cannot reach tenant operational controllers', function () {
    $operator = platformOperator();

    $this->asContext('app')
        ->actingAs($operator, 'sanctum')
        ->getJson('/api/app/users')
        ->assertForbidden();

    $this->actingAs($operator, 'sanctum')
        ->getJson('/api/tenant/customers')
        ->assertForbidden();
});

test('a tenant admin cannot use the platform portal', function () {
    $tenant = managedClinic();

    $this->asContext('portal')
        ->actingAs($tenant['admin'], 'sanctum')
        ->getJson('/api/portal/tenants')
        ->assertForbidden();
});

test('the platform still administers tenant metadata through the portal', function () {
    $tenant = managedClinic();
    $operator = platformOperator();

    $this->asContext('portal')->actingAs($operator, 'sanctum')
        ->getJson('/api/portal/tenants')
        ->assertOk()
        ->assertJsonFragment(['slug' => $tenant['tenant']->slug]);

    $this->asContext('portal')->actingAs($operator, 'sanctum')
        ->postJson("/api/portal/tenants/{$tenant['tenant']->id}/suspend", [
            'reason' => 'Non-payment investigation',
        ])
        ->assertOk();
});
