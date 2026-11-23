<?php

use App\Enums\StationType;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessConflictException;
use App\Models\Department;
use App\Models\Station;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\TenantOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function provisionPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sehat Selalu Clinic',
        'facility_name' => 'Sehat Selalu Main',
        'admin_name' => 'Admin Sehat',
        'admin_email' => 'admin@sehat.test',
        'admin_password' => 'password123',
    ], $overrides);
}

test('provisioning creates a tenant, facility, admin and default template', function () {
    $result = app(TenantOnboardingService::class)->provision(provisionPayload());

    expect($result['tenant']->status)->toBe(TenantStatus::Active)
        ->and($result['tenant']->slug)->toBe('sehat-selalu-clinic')
        ->and($result['facility']->tenant_id)->toBe($result['tenant']->id)
        ->and($result['facility']->code)->toBe('MAIN')
        ->and($result['admin']->tenant_id)->toBe($result['tenant']->id)
        ->and($result['admin']->role)->toBe(UserRole::Admin);

    $facility = $result['facility'];
    $department = Department::query()->where('facility_id', $facility->id)->firstOrFail();

    expect($department->code)->toBe('GEN');

    $station = Station::query()->where('department_id', $department->id)->firstOrFail();
    expect($station->type)->toBe(StationType::Registration);

    $workflow = Workflow::query()->where('department_id', $department->id)->firstOrFail();
    $step = WorkflowStep::query()->where('workflow_id', $workflow->id)->firstOrFail();
    expect($step->station_id)->toBe($station->id)
        ->and($step->sequence)->toBe(1);
});

test('a provisioned admin can authenticate and read their own tenant', function () {
    $result = app(TenantOnboardingService::class)->provision(provisionPayload());

    $token = $this->postJson('/api/auth/login', [
        'email' => 'admin@sehat.test',
        'password' => 'password123',
    ])->assertOk()->json('data.token');

    $this->withToken($token)
        ->getJson('/api/departments')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.code', 'GEN');

    expect($result['tenant'])->not->toBeNull();
});

test('a provisioned admin can create staff scoped to their tenant', function () {
    $result = app(TenantOnboardingService::class)->provision(provisionPayload());
    $department = Department::query()->where('facility_id', $result['facility']->id)->firstOrFail();
    $station = Station::query()->where('department_id', $department->id)->firstOrFail();

    $this->actingAs($result['admin'], 'sanctum')
        ->postJson('/api/users', [
            'name' => 'Nurse One',
            'email' => 'nurse@sehat.test',
            'password' => 'password123',
            'role' => 'staff',
            'department_id' => $department->id,
            'station_id' => $station->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.tenant_id', $result['tenant']->id)
        ->assertJsonPath('data.role', UserRole::Staff->value);
});

test('provisioning rejects a duplicate slug', function () {
    app(TenantOnboardingService::class)->provision(provisionPayload());

    app(TenantOnboardingService::class)->provision(provisionPayload([
        'admin_email' => 'other@sehat.test',
    ]));
})->throws(BusinessConflictException::class);

test('provisioning rejects a duplicate admin email', function () {
    app(TenantOnboardingService::class)->provision(provisionPayload());

    app(TenantOnboardingService::class)->provision(provisionPayload([
        'name' => 'Another Clinic',
        'admin_email' => 'admin@sehat.test',
    ]));
})->throws(BusinessConflictException::class);

test('the platform tenant endpoint provisions and requires a platform account', function () {
    $platform = User::factory()->create([
        'role' => UserRole::PlatformAdmin,
        'tenant_id' => null,
    ]);

    $this->actingAs($platform, 'sanctum')
        ->postJson('/api/platform/tenants', provisionPayload())
        ->assertCreated()
        ->assertJsonPath('data.tenant.slug', 'sehat-selalu-clinic')
        ->assertJsonPath('data.admin.email', 'admin@sehat.test');
});

test('a tenant admin cannot reach platform routes', function () {
    $result = app(TenantOnboardingService::class)->provision(provisionPayload());

    $this->actingAs($result['admin'], 'sanctum')
        ->getJson('/api/platform/tenants')
        ->assertForbidden();
});

test('an account with a null tenant but a clinic role is not granted platform access', function () {
    // A row whose tenant_id is null but role is a clinic role must not be
    // treated as platform merely because tenant_id is null.
    $user = User::factory()->create([
        'role' => UserRole::Admin,
        'tenant_id' => null,
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/platform/tenants')
        ->assertForbidden();
});
