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
        'name' => 'Kiyu Demo Clinic',
        'slug' => 'kiyu-demo',
        'status' => TenantStatus::Active,
    ]);

    $this->facility = Facility::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Kiyu Demo Facility',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $this->staffUser = User::factory()->create([
        'name' => 'Alice Resepsionis',
        'email' => 'staff@kiyu.test',
        'password' => Hash::make('password'),
        'role' => 'receptionist',
        'tenant_id' => $this->tenant->id,
    ]);
});

test('login returns a sanctum token and the user payload', function () {
    $response = $this->postJson('/api/auth/login', [
        'email' => 'staff@kiyu.test',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Login successful.')
        ->assertJsonPath('data.user.email', 'staff@kiyu.test')
        ->assertJsonPath('data.user.name', 'Alice Resepsionis')
        ->assertJsonPath('data.user.role', 'receptionist')
        ->assertJsonStructure([
            'data' => [
                'token',
                'user' => ['id', 'name', 'email', 'role', 'department_id', 'station_id', 'profile'],
            ],
        ]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.user.password'))->toBeNull();
});

test('login rejects an incorrect password', function () {
    $this->postJson('/api/auth/login', [
        'email' => 'staff@kiyu.test',
        'password' => 'wrong-password',
    ])->assertStatus(422)
        ->assertJsonPath('error_type', 'INVALID_CREDENTIALS')
        ->assertJsonPath('message', 'Email atau password salah.');
});

test('me requires authentication', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();
});

test('me returns the authenticated user for a valid bearer token', function () {
    $this->staffUser->update(['role' => 'staff']);

    $token = $this->postJson('/api/auth/login', [
        'email' => 'staff@kiyu.test',
        'password' => 'password',
    ])->json('data.token');

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'staff@kiyu.test')
        ->assertJsonPath('data.role', 'staff');
});

test('logout revokes the token so it can no longer authenticate', function () {
    $token = $this->postJson('/api/auth/login', [
        'email' => 'staff@kiyu.test',
        'password' => 'password',
    ])->json('data.token');

    $this->withToken($token)
        ->postJson('/api/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Logged out successfully.');

    $this->assertDatabaseCount('personal_access_tokens', 0);

    // The auth guard caches the resolved user within a test process, so reset
    // it before proving the revoked token can no longer authenticate.
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

test('register creates a tenant-scoped client account and ignores injected privileged roles', function () {
    $response = $this->withHeader('X-Tenant-Slug', 'kiyu-demo')->postJson('/api/auth/register', [
        'name' => 'Public Client',
        'email' => 'client@kiyu.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'admin',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'client@kiyu.test')
        ->assertJsonPath('data.user.role', 'client');

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();

    $this->assertDatabaseHas('users', [
        'email' => 'client@kiyu.test',
        'role' => 'client',
        'tenant_id' => $this->tenant->id,
    ]);
});

test('register rejects a duplicate email', function () {
    $this->withHeader('X-Tenant-Slug', 'kiyu-demo')->postJson('/api/auth/register', [
        'name' => 'Duplicate',
        'email' => 'staff@kiyu.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('staff endpoints require authentication', function () {
    $this->getJson('/api/clients')->assertUnauthorized();

    $this->postJson('/api/intake/manual', [
        'department_id' => 1,
    ])->assertUnauthorized();
});

test('public kiosk endpoints require a tenant locator but no user token', function () {
    $this->getJson('/api/departments/active')->assertNotFound();

    $this->withHeader('X-Tenant-Slug', 'kiyu-demo')
        ->getJson('/api/departments/active')
        ->assertOk();

    $this->withHeader('X-Tenant-Slug', 'kiyu-demo')
        ->getJson('/api/public/queue')
        ->assertOk();
});

test('login failures are indistinguishable across unknown email bad password and inactive tenant', function () {
    $suspended = Tenant::query()->create([
        'name' => 'Suspended Clinic',
        'slug' => 'suspended-clinic',
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
        'role' => 'receptionist',
        'tenant_id' => $suspended->id,
    ]);

    foreach ([
        ['email' => 'nobody@kiyu.test', 'password' => 'password'],
        ['email' => 'staff@kiyu.test', 'password' => 'wrong-password'],
        ['email' => 'suspended@kiyu.test', 'password' => 'password'],
    ] as $payload) {
        $this->postJson('/api/auth/login', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'INVALID_CREDENTIALS');
    }
});
