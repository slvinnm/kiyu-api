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

    $this->department = Department::create([
        'facility_id' => $this->facility->id,
        'code' => 'GEN',
        'name' => 'Poli Umum',
        'is_active' => true,
    ]);

    $this->otherDepartment = Department::create([
        'facility_id' => $this->facility->id,
        'code' => 'DNT',
        'name' => 'Poli Gigi',
        'is_active' => true,
    ]);

    $this->station = Station::create([
        'department_id' => $this->department->id,
        'name' => 'Ruang Perawat',
        'code' => 'GEN-NUR',
        'type' => StationType::Service,
        'queue_prefix' => 'N',
        'is_active' => true,
    ]);

    $this->otherStation = Station::create([
        'department_id' => $this->otherDepartment->id,
        'name' => 'Ruang Gigi',
        'code' => 'DNT-DOC',
        'type' => StationType::Service,
        'queue_prefix' => 'DG',
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create([
        'name' => 'Admin Test',
        'email' => 'admin@kiyu.test',
        'role' => 'tenant_admin',
        'tenant_id' => $this->tenant->id,
    ]);

    $this->nurse = User::factory()->create([
        'name' => 'Nurse Test',
        'email' => 'nurse@kiyu.test',
        'role' => 'staff',
        'station_id' => $this->station->id,
        'tenant_id' => $this->tenant->id,
    ]);

    $this->actingAs($this->admin, 'sanctum');
});

test('admin can list users with role and station relations', function () {
    $response = $this->getJson('/api/app/users');

    $response->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonStructure([
            'data' => [
                'data' => [
                    '*' => ['id', 'name', 'email', 'role', 'station_id', 'facilities'],
                ],
            ],
        ]);
});

test('admin can create a staff user with a hashed password', function () {
    $response = $this->postJson('/api/app/users', [
        'name' => 'New Nurse',
        'email' => 'new.nurse@kiyu.test',
        'password' => 'secret123',
        'role' => 'staff',
        'station_id' => $this->station->id,
        'facility_ids' => [$this->facility->id],
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'User created successfully.')
        ->assertJsonPath('data.email', 'new.nurse@kiyu.test')
        ->assertJsonPath('data.role', 'staff')
        ->assertJsonPath('data.station_id', $this->station->id);

    $user = User::query()->where('email', 'new.nurse@kiyu.test')->firstOrFail();

    expect(Hash::check('secret123', $user->password))->toBeTrue();
    expect($user->password)->not->toBe('secret123');
});

test('non-admin cannot list or create users', function () {
    $this->actingAs($this->nurse, 'sanctum');

    $this->getJson('/api/app/users')->assertForbidden();

    $this->postJson('/api/app/users', [
        'name' => 'Forbidden User',
        'email' => 'forbidden@kiyu.test',
        'password' => 'secret123',
        'role' => 'staff',
    ])->assertForbidden();
});

test('admin can update a user and leave the password unchanged', function () {
    $originalHash = $this->nurse->password;

    $response = $this->putJson("/api/app/users/{$this->nurse->id}", [
        'name' => 'Nurse Updated',
        'role' => 'staff',
        'station_id' => $this->station->id,
        'facility_ids' => [$this->facility->id],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Nurse Updated')
        ->assertJsonPath('data.role', 'staff');

    $this->nurse->refresh();

    expect($this->nurse->password)->toBe($originalHash);
});

test('admin can delete another user', function () {
    $this->deleteJson("/api/app/users/{$this->nurse->id}")
        ->assertOk()
        ->assertJsonPath('message', 'User deleted successfully.');

    $this->assertDatabaseMissing('users', ['id' => $this->nurse->id]);
});

test('admin cannot delete their own account', function () {
    $this->deleteJson("/api/app/users/{$this->admin->id}")
        ->assertStatus(422)
        ->assertJsonPath('error_type', 'INVALID_STATE');
});

test('a facility admin cannot grant a facility outside their scope', function () {
    $otherTenant = Tenant::factory()->create();
    $otherFacility = Facility::factory()->create(['tenant_id' => $otherTenant->id]);

    $facilityAdmin = User::factory()->create([
        'role' => 'facility_admin',
        'tenant_id' => $this->tenant->id,
    ]);

    $this->actingAs($facilityAdmin, 'sanctum')
        ->postJson('/api/app/users', [
            'name' => 'Out Of Scope',
            'email' => 'out-of-scope@kiyu.test',
            'password' => 'secret123',
            'role' => 'staff',
            'facility_ids' => [$otherFacility->id],
        ])->assertForbidden();
});

test('email must be unique', function () {
    $this->postJson('/api/app/users', [
        'name' => 'Duplicate Email',
        'email' => 'nurse@kiyu.test',
        'password' => 'secret123',
        'role' => 'staff',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('email');
});
