<?php

use App\Enums\TenantStatus;
use App\Models\Facility;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * PRD §6.1, §6.2, §7: the two frontends are separate application contexts.
 * A role may only sign in to the frontend it belongs to, and an unknown tenant
 * label is a real 404 rather than a default tenant.
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

function contextUser(Tenant|null $tenant, string $role, string $email): User
{
    return User::factory()->create([
        'email' => $email,
        'password' => Hash::make('password'),
        'role' => $role,
        'tenant_id' => $tenant?->id,
    ]);
}

test('a tenant_admin signs in to the platform portal', function () {
    $tenant = contextTenant();
    contextUser($tenant, 'tenant_admin', 'owner@kiyu.test');

    $this->postJson('/api/auth/login', [
        'email' => 'owner@kiyu.test',
        'password' => 'password',
        'context' => 'platform_portal',
    ])->assertOk()
        ->assertJsonPath('data.context', 'platform_portal')
        ->assertJsonPath('data.user.role', 'tenant_admin')
        ->assertJsonPath('data.user.tenant.slug', 'context-org');
});

test('an operator is refused the platform portal', function () {
    $tenant = contextTenant();
    contextUser($tenant, 'operator', 'operator@kiyu.test');

    $this->postJson('/api/auth/login', [
        'email' => 'operator@kiyu.test',
        'password' => 'password',
        'context' => 'platform_portal',
    ])->assertForbidden()
        ->assertJsonPath('error_type', 'WRONG_APPLICATION_CONTEXT');
});

test('a tenant_admin is refused the tenant workspace', function () {
    $tenant = contextTenant();
    contextUser($tenant, 'tenant_admin', 'owner@kiyu.test');

    $this->postJson('/api/auth/login', [
        'email' => 'owner@kiyu.test',
        'password' => 'password',
        'context' => 'tenant_workspace',
    ])->assertForbidden()
        ->assertJsonPath('error_type', 'WRONG_APPLICATION_CONTEXT');
});

test('a facility_admin operator and staff sign in to the tenant workspace', function () {
    $tenant = contextTenant();

    foreach (['facility_admin', 'operator', 'staff'] as $role) {
        contextUser($tenant, $role, "{$role}@kiyu.test");

        $this->postJson('/api/auth/login', [
            'email' => "{$role}@kiyu.test",
            'password' => 'password',
            'context' => 'tenant_workspace',
        ])->assertOk()
            ->assertJsonPath('data.context', 'tenant_workspace')
            ->assertJsonPath('data.user.role', $role);
    }
});

test('a token issued for one context cannot be used in the other', function () {
    $tenant = contextTenant();
    $admin = contextUser($tenant, 'tenant_admin', 'owner@kiyu.test');

    // Token minted by the tenant workspace frontend.
    $admin->tokens()->delete();
    $tenantToken = $admin->createToken('web', ['ctx:tenant_workspace'])->plainTextToken;

    // Platform routes require a platform-portal token.
    $this->withToken($tenantToken)
        ->getJson('/api/platform/tenants')
        ->assertForbidden();
});

test('a platform_admin cannot sign in to a tenant workspace', function () {
    contextUser(null, 'platform_admin', 'root@kiyu.test');

    $this->postJson('/api/auth/login', [
        'email' => 'root@kiyu.test',
        'password' => 'password',
        'context' => 'tenant_workspace',
    ])->assertForbidden()
        ->assertJsonPath('error_type', 'WRONG_APPLICATION_CONTEXT');
});

test('public tenant resolution returns only public fields for an active tenant', function () {
    contextTenant();

    $this->getJson('/api/public/tenant/context-org')
        ->assertOk()
        ->assertJsonPath('data.slug', 'context-org')
        ->assertJsonPath('data.name', 'Context Org')
        ->assertJsonPath('data.status', 'ACTIVE')
        ->assertJsonMissingPath('data.owner_user_id');
});

test('an unknown tenant slug is a 404', function () {
    $this->getJson('/api/public/tenant/does-not-exist')->assertNotFound();
});

test('a suspended tenant resolves as unavailable, not active', function () {
    Tenant::query()->create([
        'name' => 'Suspended Org',
        'slug' => 'suspended-org',
        'status' => TenantStatus::Suspended,
    ]);

    $this->getJson('/api/public/tenant/suspended-org')
        ->assertForbidden()
        ->assertJsonPath('data.status', 'SUSPENDED');
});
