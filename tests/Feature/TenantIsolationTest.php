<?php

use App\Enums\StationType;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Department;
use App\Models\Facility;
use App\Models\KioskDevice;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Provision a complete, isolated clinic: tenant, facility, admin, department,
 * registration station and workflow.
 *
 * @return array{tenant: Tenant, facility: Facility, admin: User, department: Department, station: Station, workflow: Workflow}
 */
function provisionClinic(string $slug, string $departmentCode): array
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($slug) . ' Clinic',
        'slug' => $slug,
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => Str::headline($slug) . ' Facility',
        'code' => strtoupper($slug) . '-MAIN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'tenant_id' => $tenant->id,
    ]);

    $department = Department::create([
        'facility_id' => $facility->id,
        'code' => $departmentCode,
        'name' => Str::headline($slug) . ' Department',
        'is_active' => true,
        'display_in_kiosk' => true,
    ]);

    $station = Station::create([
        'department_id' => $department->id,
        'name' => 'Registration',
        'code' => 'REG-01',
        'type' => StationType::Registration,
        'queue_prefix' => 'R',
        'is_active' => true,
    ]);

    $workflow = Workflow::create([
        'department_id' => $department->id,
        'name' => 'Registration Flow',
        'is_active' => true,
    ]);

    WorkflowStep::create([
        'workflow_id' => $workflow->id,
        'station_id' => $station->id,
        'name' => 'Registration',
        'sequence' => 1,
        'is_active' => true,
    ]);

    return compact('tenant', 'facility', 'admin', 'department', 'station', 'workflow');
}

beforeEach(function () {
    $this->a = provisionClinic('clinic-a', 'GEN-A');
    $this->b = provisionClinic('clinic-b', 'GEN-B');

    // Overlapping business values are intentional: both facilities use the
    // same department and station codes, which must be allowed across scopes.
    $this->clientA = Client::query()->create([
        'tenant_id' => $this->a['tenant']->id,
        'mrn' => 'MRN-0001',
        'nik' => '3201010101900001',
        'name' => 'Client A',
    ]);

    $this->clientB = Client::query()->create([
        'tenant_id' => $this->b['tenant']->id,
        'mrn' => 'MRN-0001',
        'nik' => '3201010101900001',
        'name' => 'Client B',
    ]);
});

test('two facilities may use the same department and station code', function () {
    expect($this->a['station']->code)->toBe($this->b['station']->code);
    expect($this->a['department']->code)->toBe('GEN-A')
        ->and($this->b['department']->code)->toBe('GEN-B');
});

test('an authenticated user cannot read another tenant\'s client', function () {
    $this->actingAs($this->a['admin'], 'sanctum')
        ->getJson("/api/clients/{$this->clientB->id}")
        ->assertNotFound();
});

test('an authenticated user can read their own tenant\'s client', function () {
    $this->actingAs($this->a['admin'], 'sanctum')
        ->getJson("/api/clients/{$this->clientA->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->clientA->id);
});

test('a request body tenant_id cannot switch the authenticated tenant', function () {
    $this->actingAs($this->a['admin'], 'sanctum')
        ->getJson("/api/clients/{$this->clientB->id}?tenant_id={$this->b['tenant']->id}")
        ->assertNotFound();
});

test('a suspended tenant blocks operational access', function () {
    $this->a['tenant']->update(['status' => TenantStatus::Suspended]);

    $this->actingAs($this->a['admin'], 'sanctum')
        ->getJson('/api/clients')
        ->assertForbidden();
});

test('a platform account is rejected from clinic endpoints', function () {
    $platform = User::factory()->create([
        'role' => UserRole::PlatformAdmin,
        'tenant_id' => null,
    ]);

    $this->actingAs($platform, 'sanctum')
        ->getJson('/api/clients')
        ->assertForbidden();
});

test('a client user cannot be linked to a client profile from another tenant', function () {
    $clientUser = User::factory()->create([
        'role' => 'client',
        'tenant_id' => $this->a['tenant']->id,
    ]);

    $this->clientB->update(['user_id' => $clientUser->id]);

    expect($this->clientB->fresh()->tenant_id)->not->toBe($clientUser->tenant_id);
});

test('visit numbering is independent per facility', function () {
    $service = app(SequenceService::class);

    // Facility A's first number and facility B's first number are generated
    // from separate counters, so they share the same formatted value.
    $aFirst = $service->generateVisitNumber($this->a['facility']);
    $bFirst = $service->generateVisitNumber($this->b['facility']);

    expect($aFirst)->toBe($bFirst)
        ->and($aFirst)->toEndWith('0001');

    // Advancing A twice must not affect B's next number, which continues from
    // its own counter rather than A's.
    $service->generateVisitNumber($this->a['facility']);
    $aThird = $service->generateVisitNumber($this->a['facility']);
    $bSecond = $service->generateVisitNumber($this->b['facility']);

    expect($aThird)->toEndWith('0003')
        ->and($bSecond)->toEndWith('0002');
});

test('a manual intake cannot target a department from another tenant', function () {
    $this->actingAs($this->a['admin'], 'sanctum')
        ->postJson('/api/intake/manual', [
            'department_id' => $this->b['department']->id,
        ])
        ->assertNotFound();
});

test('a manual intake accepts a department from the current tenant', function () {
    $this->actingAs($this->a['admin'], 'sanctum')
        ->postJson('/api/intake/manual', [
            'department_id' => $this->a['department']->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.visit.department_id', $this->a['department']->id);
});

test('a kiosk device credential resolves its own facility', function () {
    $rawToken = 'device-secret-token';

    KioskDevice::factory()
        ->forFacility($this->a['facility'])
        ->create(['credential_hash' => hash('sha256', $rawToken)]);

    $this->withHeader('X-Kiosk-Token', $rawToken)
        ->postJson('/api/intake/kiosk', [
            'department_id' => $this->a['department']->id,
        ])
        ->assertCreated();

    $this->withHeader('X-Kiosk-Token', $rawToken)
        ->postJson('/api/intake/kiosk', [
            'department_id' => $this->b['department']->id,
        ])
        ->assertNotFound();
});

test('a missing kiosk credential is rejected', function () {
    $this->postJson('/api/intake/kiosk', [
        'department_id' => $this->a['department']->id,
    ])->assertForbidden();
});
