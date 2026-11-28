<?php

use App\Enums\StationType;
use App\Enums\TenantStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * Department-scoped user management (PRD §8.3, §8.4): only Operator and Staff,
 * and the station must match the role's station type and the department.
 */
beforeEach(function () {
    Cache::flush();

    $tenant = Tenant::query()->create([
        'name' => 'Scoped Org',
        'slug' => 'scoped-org',
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $department = Department::create([
        'facility_id' => $facility->id,
        'code' => 'GEN',
        'name' => 'General',
        'is_active' => true,
    ]);

    $otherDepartment = Department::create([
        'facility_id' => $facility->id,
        'code' => 'OTH',
        'name' => 'Other',
        'is_active' => true,
    ]);

    $intake = Station::create([
        'department_id' => $department->id,
        'name' => 'Intake',
        'code' => 'GEN-INT',
        'type' => StationType::Intake,
        'queue_prefix' => 'R',
        'is_active' => true,
    ]);

    $service = Station::create([
        'department_id' => $department->id,
        'name' => 'Service',
        'code' => 'GEN-SVC',
        'type' => StationType::Service,
        'queue_prefix' => 'S',
        'is_active' => true,
    ]);

    $otherService = Station::create([
        'department_id' => $otherDepartment->id,
        'name' => 'Other Service',
        'code' => 'OTH-SVC',
        'type' => StationType::Service,
        'queue_prefix' => 'O',
        'is_active' => true,
    ]);

    $admin = User::factory()->create([
        'role' => 'tenant_admin',
        'tenant_id' => $tenant->id,
    ]);

    $this->actingAs($admin, 'sanctum');

    $this->department = $department;
    $this->otherDepartment = $otherDepartment;
    $this->intakeStation = $intake;
    $this->serviceStation = $service;
    $this->otherServiceStation = $otherService;
});

function scopedUserPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'New Staff',
        'email' => 'new.staff@scoped.test',
        'password' => 'password123',
    ], $overrides);
}

test('an operator must be an INTAKE station in the department', function () {
    $this->postJson('/api/app/users', scopedUserPayload([
        'role' => 'operator',
        'department_id' => $this->department->id,
        'station_id' => $this->serviceStation->id,
    ]))->assertStatus(422)->assertJsonValidationErrors('station_id');

    $this->postJson('/api/app/users', scopedUserPayload([
        'email' => 'op@scoped.test',
        'role' => 'operator',
        'department_id' => $this->department->id,
        'station_id' => $this->intakeStation->id,
    ]))->assertCreated();
});

test('a staff member must be a SERVICE CASHIER or COUNTER station', function () {
    $this->postJson('/api/app/users', scopedUserPayload([
        'role' => 'staff',
        'department_id' => $this->department->id,
        'station_id' => $this->intakeStation->id,
    ]))->assertStatus(422)->assertJsonValidationErrors('station_id');

    $this->postJson('/api/app/users', scopedUserPayload([
        'email' => 'staff@scoped.test',
        'role' => 'staff',
        'department_id' => $this->department->id,
        'station_id' => $this->serviceStation->id,
    ]))->assertCreated();
});

test('a station from another department is rejected', function () {
    $this->postJson('/api/app/users', scopedUserPayload([
        'role' => 'staff',
        'department_id' => $this->department->id,
        'station_id' => $this->otherServiceStation->id,
    ]))->assertStatus(422)->assertJsonValidationErrors('station_id');
});

test('privileged roles are rejected inside a department', function () {
    foreach (['facility_admin', 'tenant_admin', 'customer'] as $role) {
        $this->postJson('/api/app/users', scopedUserPayload([
            'email' => "x-{$role}@scoped.test",
            'role' => $role,
            'department_id' => $this->department->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('role');
    }
});

test('users can be listed filtered by department', function () {
    User::factory()->create([
        'role' => 'staff',
        'tenant_id' => $this->department->facility->tenant_id,
        'station_id' => $this->serviceStation->id,
    ]);

    $this->getJson('/api/app/users?department_id=' . $this->department->id)
        ->assertOk()
        ->assertJsonPath('data.total', 1);
});
