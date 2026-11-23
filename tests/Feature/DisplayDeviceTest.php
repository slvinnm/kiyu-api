<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\StationType;
use App\Enums\TenantStatus;
use App\Models\Department;
use App\Models\DisplayDevice;
use App\Models\Facility;
use App\Models\Plan;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, int>  $limits
 * @return array{tenant: Tenant, facility: Facility, admin: User, department: Department, station: Station}
 */
function displayClinic(array $limits): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Display Clinic',
        'slug' => 'display-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);

    $department = Department::create([
        'facility_id' => $facility->id,
        'code' => 'GEN',
        'name' => 'General',
        'is_active' => true,
    ]);

    $station = Station::create([
        'department_id' => $department->id,
        'name' => 'Registration',
        'code' => 'REG-01',
        'type' => StationType::Registration,
        'queue_prefix' => 'R',
        'is_active' => true,
    ]);

    $plan = Plan::query()->create(['code' => 'D-' . uniqid(), 'name' => 'D', 'is_active' => true]);
    $plan->entitlements()->create(['feature_code' => Feature::QueueManagement->value, 'value' => null]);

    foreach ($limits as $featureValue => $limit) {
        $plan->entitlements()->create(['feature_code' => $featureValue, 'value' => ['limit' => $limit]]);
    }

    app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);

    return compact('tenant', 'facility', 'admin', 'department', 'station');
}

test('admin registers a display and receives a pairing code', function () {
    $clinic = displayClinic([Feature::MaxDisplayDevices->value => 2]);

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/display-devices', ['name' => 'Lobby Screen'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['device', 'pairing_code', 'pairing_expires_at']])
        ->assertJsonMissingPath('data.device.credential_hash');
});

test('the pairing code is never stored in plain text', function () {
    $clinic = displayClinic([Feature::MaxDisplayDevices->value => 2]);

    $code = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/display-devices', ['name' => 'Screen'])
        ->json('data.pairing_code');

    $stored = DisplayDevice::query()->firstOrFail();

    expect($stored->pairing_code_hash)->toBe(hash('sha256', $code))
        ->and($stored->getAttribute('pairing_code_hash'))->not->toBe($code)
        ->and($stored->paired_at)->toBeNull();
});

test('a display redeems its code for a credential exactly once', function () {
    $clinic = displayClinic([Feature::MaxDisplayDevices->value => 1]);

    $code = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/display-devices', ['name' => 'Screen'])
        ->json('data.pairing_code');

    $credential = $this->postJson('/api/display/pair', ['pairing_code' => $code])
        ->assertOk()
        ->assertJsonPath('data.device.tenant.slug', $clinic['tenant']->slug)
        ->json('data.credential');

    $stored = DisplayDevice::query()->firstOrFail();
    expect($stored->paired_at)->not->toBeNull()
        ->and($stored->credential_hash)->toBe(hash('sha256', $credential))
        ->and($stored->pairing_code_hash)->toBeNull();

    // Replay is rejected.
    $this->postJson('/api/display/pair', ['pairing_code' => $code])->assertNotFound();
});

test('a paired display resolves the public queue with its token', function () {
    $clinic = displayClinic([Feature::MaxDisplayDevices->value => 1]);

    $code = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/display-devices', ['name' => 'Screen'])
        ->json('data.pairing_code');

    $credential = $this->postJson('/api/display/pair', ['pairing_code' => $code])->json('data.credential');

    $this->withHeader('X-Display-Token', $credential)
        ->getJson('/api/public/queue')
        ->assertOk()
        ->assertJsonPath('data.0.station_code', 'REG-01');
});

test('an unpaired display cannot authenticate', function () {
    $clinic = displayClinic([Feature::MaxDisplayDevices->value => 1]);

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/display-devices', ['name' => 'Screen'])
        ->assertCreated();

    $this->withHeader('X-Display-Token', 'anything')
        ->getJson('/api/public/queue')
        ->assertForbidden();
});

test('display creation is capped by max_display_devices', function () {
    $clinic = displayClinic([Feature::MaxDisplayDevices->value => 1]);

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/display-devices', ['name' => 'One'])
        ->assertCreated();

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/display-devices', ['name' => 'Two'])
        ->assertStatus(409);

    $this->assertDatabaseCount('display_devices', 1);
});

test('a revoked display cannot resolve the public queue', function () {
    $clinic = displayClinic([Feature::MaxDisplayDevices->value => 1]);

    $code = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/display-devices', ['name' => 'Screen'])
        ->json('data.pairing_code');

    $credential = $this->postJson('/api/display/pair', ['pairing_code' => $code])->json('data.credential');
    $id = DisplayDevice::query()->firstOrFail()->id;

    $this->actingAs($clinic['admin'], 'sanctum')
        ->deleteJson("/api/display-devices/{$id}")
        ->assertOk();

    $this->withHeader('X-Display-Token', $credential)
        ->getJson('/api/public/queue')
        ->assertForbidden();
});

test('a pairing code cannot be reissued once the display is paired', function () {
    $clinic = displayClinic([Feature::MaxDisplayDevices->value => 1]);

    $code = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/display-devices', ['name' => 'Screen'])
        ->json('data.pairing_code');

    $this->postJson('/api/display/pair', ['pairing_code' => $code])->assertOk();
    $id = DisplayDevice::query()->firstOrFail()->id;

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson("/api/display-devices/{$id}/pairing-code")
        ->assertStatus(409);
});

test('a non-admin cannot manage display devices', function () {
    $clinic = displayClinic([Feature::MaxDisplayDevices->value => 2]);
    $staff = User::factory()->create(['role' => 'staff', 'tenant_id' => $clinic['tenant']->id]);

    $this->actingAs($staff, 'sanctum')
        ->postJson('/api/display-devices', ['name' => 'X'])
        ->assertForbidden();
});
