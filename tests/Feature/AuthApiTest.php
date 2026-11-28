<?php

use App\Enums\TenantStatus;
use App\Models\Facility;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

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

    // Self-registration is opt-in per tenant.
    $this->tenant->update(['allow_public_registration' => true]);

    $this->staffUser = User::factory()->create([
        'name' => 'Alice Operator',
        'email' => 'staff@kiyu.test',
        'password' => Hash::make('password'),
        'role' => 'operator',
        'tenant_id' => $this->tenant->id,
    ]);
});

test('tenant login returns a scoped token and the user payload', function () {
    $response = $this->withTenantOrigin('kiyu-demo')->postJson('/api/tenant/auth/login', [
        'email' => 'staff@kiyu.test',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Login successful.')
        ->assertJsonPath('data.context', 'tenant')
        ->assertJsonPath('data.user.email', 'staff@kiyu.test')
        ->assertJsonPath('data.user.name', 'Alice Operator')
        ->assertJsonPath('data.user.role', 'operator')
        ->assertJsonStructure([
            'data' => [
                'token',
                'user' => ['id', 'name', 'email', 'role', 'facility_ids', 'station_id', 'profile'],
            ],
        ]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.user.password'))->toBeNull();
});

test('tenant login rejects an incorrect password', function () {
    $this->withTenantOrigin('kiyu-demo')->postJson('/api/tenant/auth/login', [
        'email' => 'staff@kiyu.test',
        'password' => 'wrong-password',
    ])->assertStatus(422)
        ->assertJsonPath('error_type', 'INVALID_CREDENTIALS')
        ->assertJsonPath('message', 'Email atau password salah.');
});

test('tenant login refuses a foreign tenant origin', function () {
    Tenant::query()->create([
        'name' => 'Other Org',
        'slug' => 'other-org',
        'status' => TenantStatus::Active,
    ]);

    $this->withTenantOrigin('other-org')->postJson('/api/tenant/auth/login', [
        'email' => 'staff@kiyu.test',
        'password' => 'password',
    ])->assertForbidden()
        ->assertJsonPath('error_type', 'WRONG_TENANT_ORIGIN');
});

test('me requires authentication', function () {
    $this->withTenantOrigin('kiyu-demo')->getJson('/api/tenant/auth/me')->assertUnauthorized();
});

test('me returns the authenticated user for a valid bearer token', function () {
    $this->staffUser->update(['role' => 'staff']);

    $token = $this->withTenantOrigin('kiyu-demo')->postJson('/api/tenant/auth/login', [
        'email' => 'staff@kiyu.test',
        'password' => 'password',
    ])->json('data.token');

    $this->withTenantOrigin('kiyu-demo')
        ->withToken($token)
        ->getJson('/api/tenant/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'staff@kiyu.test')
        ->assertJsonPath('data.role', 'staff');
});

test('logout revokes the token so it can no longer authenticate', function () {
    $token = $this->withTenantOrigin('kiyu-demo')->postJson('/api/tenant/auth/login', [
        'email' => 'staff@kiyu.test',
        'password' => 'password',
    ])->json('data.token');

    $this->withTenantOrigin('kiyu-demo')
        ->withToken($token)
        ->postJson('/api/tenant/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Logged out successfully.');

    $this->assertDatabaseCount('personal_access_tokens', 0);

    // The auth guard caches the resolved user within a test process, so reset
    // it before proving the revoked token can no longer authenticate.
    $this->app['auth']->forgetGuards();

    $this->withTenantOrigin('kiyu-demo')
        ->withToken($token)
        ->getJson('/api/tenant/auth/me')
        ->assertUnauthorized();
});

test('customer register refuses when the tenant has not enabled public registration', function () {
    $this->tenant->update(['allow_public_registration' => false]);

    $this->withTenantOrigin('kiyu-demo')->postJson('/api/customer/auth/register', [
        'name' => 'Blocked Customer',
        'email' => 'blocked@kiyu.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertForbidden();
});

test('customer register creates a tenant-scoped customer account and ignores injected privileged roles', function () {
    $response = $this->withTenantOrigin('kiyu-demo')->postJson('/api/customer/auth/register', [
        'name' => 'Public Customer',
        'email' => 'customer@kiyu.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'tenant_admin',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'customer@kiyu.test')
        ->assertJsonPath('data.user.role', 'customer');

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();

    $this->assertDatabaseHas('users', [
        'email' => 'customer@kiyu.test',
        'role' => 'customer',
        'tenant_id' => $this->tenant->id,
    ]);
});

test('customer register rejects a duplicate email', function () {
    $this->withTenantOrigin('kiyu-demo')->postJson('/api/customer/auth/register', [
        'name' => 'Duplicate',
        'email' => 'staff@kiyu.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('staff endpoints require authentication', function () {
    $this->withTenantOrigin('kiyu-demo')->getJson('/api/tenant/customers')->assertUnauthorized();

    $this->withTenantOrigin('kiyu-demo')->postJson('/api/tenant/intake/manual', [
        'department_id' => 1,
    ])->assertUnauthorized();
});

test('public kiosk endpoints require a tenant locator but no user token', function () {
    $this->getJson('/api/tenant/public/departments/active')->assertNotFound();

    $this->withHeader('X-Tenant-Slug', 'kiyu-demo')
        ->getJson('/api/tenant/public/departments/active')
        ->assertOk();

    $this->withHeader('X-Tenant-Slug', 'kiyu-demo')
        ->getJson('/api/tenant/public/queue')
        ->assertOk();
});

test('login failures are indistinguishable across unknown email bad password and inactive tenant', function () {
    $suspended = Tenant::query()->create([
        'name' => 'Suspended Organization',
        'slug' => 'suspended-org',
        'status' => TenantStatus::Suspended,
    ]);

    Facility::query()->create([
        'tenant_id' => $suspended->id,
        'name' => 'Suspended Facility',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    User::factory()->create([
        'email' => 'suspended@kiyu.test',
        'password' => Hash::make('password'),
        'role' => 'operator',
        'tenant_id' => $suspended->id,
    ]);

    foreach ([
        ['email' => 'nobody@kiyu.test', 'password' => 'password'],
        ['email' => 'staff@kiyu.test', 'password' => 'wrong-password'],
        ['email' => 'suspended@kiyu.test', 'password' => 'password'],
    ] as $payload) {
        $this->withTenantOrigin('kiyu-demo')->postJson('/api/tenant/auth/login', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'INVALID_CREDENTIALS');
    }
});
