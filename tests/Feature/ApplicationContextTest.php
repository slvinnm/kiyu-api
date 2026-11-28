<?php

use App\Enums\TenantStatus;
use App\Models\Facility;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * The four application contexts (Public, App, Tenant, Portal) plus the
 * self-service Customer context: a role may only sign in to the context it
 * belongs to, and each login issues a context-scoped token.
 */
function contextTenant(): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => 'Context Org',
        'slug' => 'context-org',
        'status' => TenantStatus::Active,
        'allow_public_registration' => true,
    ]);

    Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    return $tenant;
}

function contextUser(?Tenant $tenant, string $role, string $email): User
{
    return User::factory()->create([
        'email' => $email,
        'password' => Hash::make('password'),
        'role' => $role,
        'tenant_id' => $tenant?->id,
    ]);
}

test('a tenant_admin signs in to the app context', function () {
    $tenant = contextTenant();
    contextUser($tenant, 'tenant_admin', 'owner@kiyu.test');

    $this->asContext('app')->postJson('/api/app/auth/login', [
        'email' => 'owner@kiyu.test',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.context', 'app')
        ->assertJsonPath('data.user.role', 'tenant_admin')
        ->assertJsonPath('data.user.tenant.slug', 'context-org');
});

test('an operator is refused the app context', function () {
    $tenant = contextTenant();
    contextUser($tenant, 'operator', 'operator@kiyu.test');

    $this->asContext('app')->postJson('/api/app/auth/login', [
        'email' => 'operator@kiyu.test',
        'password' => 'password',
    ])->assertStatus(422)
        ->assertJsonPath('error_type', 'INVALID_CREDENTIALS');
});

test('a tenant_admin is refused the tenant workspace', function () {
    $tenant = contextTenant();
    contextUser($tenant, 'tenant_admin', 'owner@kiyu.test');

    $this->withTenantOrigin('context-org')->postJson('/api/tenant/auth/login', [
        'email' => 'owner@kiyu.test',
        'password' => 'password',
    ])->assertStatus(422)
        ->assertJsonPath('error_type', 'INVALID_CREDENTIALS');
});

test('a facility_admin operator and staff sign in to the tenant workspace', function () {
    $tenant = contextTenant();

    foreach (['facility_admin', 'operator', 'staff'] as $role) {
        contextUser($tenant, $role, "{$role}@kiyu.test");

        $this->withTenantOrigin('context-org')->postJson('/api/tenant/auth/login', [
            'email' => "{$role}@kiyu.test",
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.context', 'tenant')
            ->assertJsonPath('data.user.role', $role);
    }
});

test('a token issued for one context cannot be used in another', function () {
    $tenant = contextTenant();
    $admin = contextUser($tenant, 'tenant_admin', 'owner@kiyu.test');

    // A token minted by the Tenant workspace cannot reach the Portal.
    $admin->tokens()->delete();
    $tenantToken = $admin->createToken('web', ['ctx:tenant'])->plainTextToken;

    $this->asContext('portal')->withToken($tenantToken)
        ->getJson('/api/portal/tenants')
        ->assertForbidden();
});

test('a platform_admin cannot sign in to a tenant workspace', function () {
    contextUser(null, 'platform_admin', 'root@kiyu.test');

    $this->withTenantOrigin('context-org')->postJson('/api/tenant/auth/login', [
        'email' => 'root@kiyu.test',
        'password' => 'password',
    ])->assertStatus(422)
        ->assertJsonPath('error_type', 'INVALID_CREDENTIALS');
});

test('public tenant resolution returns only public fields for an active tenant', function () {
    contextTenant();

    $this->getJson('/api/public/tenants/context-org')
        ->assertOk()
        ->assertJsonPath('data.slug', 'context-org')
        ->assertJsonPath('data.name', 'Context Org')
        ->assertJsonPath('data.status', 'ACTIVE')
        ->assertJsonMissingPath('data.owner_user_id');
});

test('an unknown tenant slug is a 404', function () {
    $this->getJson('/api/public/tenants/does-not-exist')->assertNotFound();
});

test('a suspended tenant resolves as unavailable, not active', function () {
    Tenant::query()->create([
        'name' => 'Suspended Org',
        'slug' => 'suspended-org',
        'status' => TenantStatus::Suspended,
    ]);

    $this->getJson('/api/public/tenants/suspended-org')
        ->assertForbidden()
        ->assertJsonPath('data.status', 'SUSPENDED');
});
