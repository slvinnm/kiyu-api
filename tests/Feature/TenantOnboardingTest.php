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
        'name' => 'Sehat Selalu Organization',
        'facility_name' => 'Sehat Selalu Main',
        'admin_name' => 'Admin Sehat',
        'admin_email' => 'admin@sehat.test',
        'admin_password' => 'password123',
    ], $overrides);
}

test('provisioning creates a tenant, facility, admin and default template', function () {
    $result = app(TenantOnboardingService::class)->provision(provisionPayload());

    expect($result['tenant']->status)->toBe(TenantStatus::Active)
        ->and($result['tenant']->slug)->toBe('sehat-selalu-organization')
        ->and($result['facility']->tenant_id)->toBe($result['tenant']->id)
        ->and($result['facility']->code)->toBe('MAIN')
        ->and($result['tenant_admin']->tenant_id)->toBe($result['tenant']->id)
        ->and($result['tenant_admin']->role)->toBe(UserRole::TenantAdmin);

    $facility = $result['facility'];
    $department = Department::query()->where('facility_id', $facility->id)->firstOrFail();

    expect($department->code)->toBe('GEN');

    $station = Station::query()->where('department_id', $department->id)->firstOrFail();
    expect($station->type)->toBe(StationType::Intake);

    $workflow = Workflow::query()->where('department_id', $department->id)->firstOrFail();
    $step = WorkflowStep::query()->where('workflow_id', $workflow->id)->firstOrFail();
    expect($step->station_id)->toBe($station->id)
        ->and($step->sequence)->toBe(1);
});

test('a provisioned admin can authenticate and read their own tenant', function () {
    $result = app(TenantOnboardingService::class)->provision(provisionPayload());

    $token = $this->asContext('app')->postJson('/api/app/auth/login', [
        'email' => 'admin@sehat.test',
        'password' => 'password123',
    ])->assertOk()->json('data.token');

    $this->withToken($token)
        ->asContext('app')
        ->getJson('/api/app/facilities')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'MAIN');

    expect($result['tenant'])->not->toBeNull();
});

test('a provisioned admin can create staff scoped to their tenant', function () {
    $result = app(TenantOnboardingService::class)->provision(provisionPayload());
    $department = Department::query()->where('facility_id', $result['facility']->id)->firstOrFail();
    // Staff belong at a SERVICE station; the default template only seeds an
    // INTAKE station, so add one here (PRD §8.4).
    $station = Station::query()->create([
        'department_id' => $department->id,
        'name' => 'Service Counter',
        'code' => 'SVC-01',
        'type' => StationType::Service,
        'queue_prefix' => 'S',
        'is_active' => true,
    ]);

    $this->actingAs($result['tenant_admin'], 'sanctum')
        ->postJson('/api/app/users', [
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
        'name' => 'Another Organization',
        'admin_email' => 'admin@sehat.test',
    ]));
})->throws(BusinessConflictException::class);

test('the platform tenant endpoint provisions and requires a platform account', function () {
    $platform = User::factory()->create([
        'role' => UserRole::PlatformAdmin,
        'tenant_id' => null,
    ]);

    $this->actingAs($platform, 'sanctum')
        ->postJson('/api/portal/tenants', provisionPayload())
        ->assertCreated()
        ->assertJsonPath('data.tenant.slug', 'sehat-selalu-organization')
        ->assertJsonPath('data.tenant_admin.email', 'admin@sehat.test');
});

test('a tenant admin cannot reach platform routes', function () {
    $result = app(TenantOnboardingService::class)->provision(provisionPayload());

    $this->actingAs($result['tenant_admin'], 'sanctum')
        ->getJson('/api/portal/tenants')
        ->assertForbidden();
});

test('an account with a null tenant but a tenant role is not granted platform access', function () {
    // A row whose tenant_id is null but role is a tenant role must not be
    // treated as platform merely because tenant_id is null.
    $user = User::factory()->create([
        'role' => UserRole::TenantAdmin,
        'tenant_id' => null,
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/portal/tenants')
        ->assertForbidden();
});
