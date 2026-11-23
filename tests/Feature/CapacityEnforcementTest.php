<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\KioskDevice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Provision a tenant on a plan with explicit limits.
 *
 * @param  array<string, int>  $limits
 * @return array{tenant: Tenant, facility: Facility, admin: User, department: Department}
 */
function capacityClinic(array $limits, ?string $slug = null): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Capacity Clinic',
        'slug' => $slug ?? 'capacity-' . uniqid(),
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

    $plan = Plan::query()->create(['code' => 'PLAN-' . uniqid(), 'name' => 'Plan', 'is_active' => true]);
    $plan->entitlements()->create(['feature_code' => Feature::QueueManagement->value, 'value' => null]);

    foreach ($limits as $featureValue => $limit) {
        $plan->entitlements()->create([
            'feature_code' => $featureValue,
            'value' => ['limit' => $limit],
        ]);
    }

    app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);

    return compact('tenant', 'facility', 'admin', 'department');
}

test('a tenant cannot create a kiosk device beyond its limit', function () {
    $clinic = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/kiosk-devices', ['name' => 'Kiosk 1'])
        ->assertCreated()
        ->assertJsonPath('data.device.name', 'Kiosk 1')
        ->assertJsonStructure(['data' => ['device', 'pairing_code']]);

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/kiosk-devices', ['name' => 'Kiosk 2'])
        ->assertStatus(409);

    $this->assertDatabaseCount('kiosk_devices', 1);
});

test('the pairing code is returned once and never persisted in plain text', function () {
    $clinic = capacityClinic([Feature::MaxKioskDevices->value => 2]);

    $pairingCode = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/kiosk-devices', ['name' => 'Kiosk'])
        ->json('data.pairing_code');

    expect($pairingCode)->toBeString()->not->toBeEmpty();

    $stored = KioskDevice::query()->firstOrFail();
    expect($stored->pairing_code_hash)->toBe(hash('sha256', $pairingCode))
        ->and($stored->getAttribute('pairing_code_hash'))->not->toBe($pairingCode);

    // The list endpoint never exposes the hashes or the code.
    $this->actingAs($clinic['admin'], 'sanctum')
        ->getJson('/api/kiosk-devices')
        ->assertOk()
        ->assertJsonMissingPath('data.0.credential_hash')
        ->assertJsonMissingPath('data.0.pairing_code_hash');
});

test('a device redeems its pairing code for a credential exactly once', function () {
    $clinic = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $pairingCode = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/kiosk-devices', ['name' => 'Kiosk'])
        ->json('data.pairing_code');

    $credential = $this->postJson('/api/kiosk/pair', ['pairing_code' => $pairingCode])
        ->assertOk()
        ->assertJsonPath('data.device.tenant.slug', $clinic['tenant']->slug)
        ->json('data.credential');

    expect($credential)->toBeString()->not->toBeEmpty();

    $stored = KioskDevice::query()->firstOrFail();
    expect($stored->credential_hash)->toBe(hash('sha256', $credential))
        ->and($stored->pairing_code_hash)->toBeNull();

    // The code is burned: a replay is rejected.
    $this->postJson('/api/kiosk/pair', ['pairing_code' => $pairingCode])
        ->assertNotFound();
});

test('a revoked kiosk device cannot resolve a tenant', function () {
    $clinic = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $pairingCode = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/kiosk-devices', ['name' => 'Kiosk'])
        ->json('data.pairing_code');

    $credential = $this->postJson('/api/kiosk/pair', ['pairing_code' => $pairingCode])
        ->json('data.credential');

    $deviceId = KioskDevice::query()->firstOrFail()->id;

    $this->actingAs($clinic['admin'], 'sanctum')
        ->deleteJson("/api/kiosk-devices/{$deviceId}")
        ->assertOk();

    // A revoked device is rejected on the kiosk intake route.
    $this->withHeader('X-Kiosk-Token', $credential)
        ->postJson('/api/intake/kiosk', ['department_id' => $clinic['department']->id])
        ->assertForbidden();
});

test('an unpaired device cannot authenticate with its placeholder credential', function () {
    $clinic = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    // Register but never redeem the pairing code.
    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/kiosk-devices', ['name' => 'Kiosk'])
        ->assertCreated();

    $device = KioskDevice::query()->firstOrFail();
    expect($device->paired_at)->toBeNull()
        ->and($device->isUsable())->toBeFalse();

    // No credential was ever issued, so the kiosk route rejects any token.
    $this->withHeader('X-Kiosk-Token', 'anything')
        ->postJson('/api/intake/kiosk', ['department_id' => $clinic['department']->id])
        ->assertForbidden();
});

test('the device list reports pairing state but never exposes hashes', function () {
    $clinic = capacityClinic([Feature::MaxKioskDevices->value => 2]);

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/kiosk-devices', ['name' => 'Awaiting'])
        ->assertCreated();

    $this->actingAs($clinic['admin'], 'sanctum')
        ->getJson('/api/kiosk-devices')
        ->assertOk()
        ->assertJsonPath('data.0.is_paired', false)
        ->assertJsonPath('data.0.is_awaiting_pairing', true)
        ->assertJsonMissingPath('data.0.credential_hash')
        ->assertJsonMissingPath('data.0.pairing_code_hash');
});

test('a pairing code cannot be reissued for an already-paired device', function () {
    $clinic = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $pairingCode = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/kiosk-devices', ['name' => 'Kiosk'])
        ->json('data.pairing_code');

    $this->postJson('/api/kiosk/pair', ['pairing_code' => $pairingCode])->assertOk();

    $deviceId = KioskDevice::query()->firstOrFail()->id;

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson("/api/kiosk-devices/{$deviceId}/pairing-code")
        ->assertStatus(409);
});

test('a pairing code can be reissued for a device that has not paired', function () {
    $clinic = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $firstCode = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/kiosk-devices', ['name' => 'Kiosk'])
        ->json('data.pairing_code');

    $deviceId = KioskDevice::query()->firstOrFail()->id;

    $secondCode = $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson("/api/kiosk-devices/{$deviceId}/pairing-code")
        ->assertOk()
        ->json('data.pairing_code');

    expect($secondCode)->not->toBe($firstCode);

    // The superseded code no longer works; the new one does.
    $this->postJson('/api/kiosk/pair', ['pairing_code' => $firstCode])->assertNotFound();

    $this->postJson('/api/kiosk/pair', ['pairing_code' => $secondCode])->assertOk();
});

test('a tenant cannot create a facility beyond its limit', function () {
    $clinic = capacityClinic([Feature::MaxFacilities->value => 1]);

    // The onboarding facility already counts as 1.
    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/facilities', ['name' => 'Second', 'code' => 'SECOND'])
        ->assertStatus(409);

    $this->assertDatabaseCount('facilities', 1);
});

test('a tenant within its facility limit can add a facility', function () {
    $clinic = capacityClinic([Feature::MaxFacilities->value => 2]);

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/facilities', ['name' => 'Second', 'code' => 'SECOND'])
        ->assertCreated()
        ->assertJsonPath('data.code', 'SECOND');

    $this->assertDatabaseCount('facilities', 2);
});

test('a tenant cannot create staff beyond its seat limit', function () {
    // admin already exists = 1 seat used.
    $clinic = capacityClinic([Feature::MaxStaff->value => 2]);

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/users', [
            'name' => 'Staff One',
            'email' => 'staff1@cap.test',
            'password' => 'password123',
            'role' => 'staff',
        ])
        ->assertCreated();

    // Second staff would exceed 2 (admin + 1).
    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/users', [
            'name' => 'Staff Two',
            'email' => 'staff2@cap.test',
            'password' => 'password123',
            'role' => 'staff',
        ])
        ->assertStatus(409);
});

test('creating a client account does not consume a staff seat', function () {
    $clinic = capacityClinic([Feature::MaxStaff->value => 1]);

    // At the seat limit (admin only), but a client role must still be creatable.
    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/users', [
            'name' => 'Client User',
            'email' => 'clientuser@cap.test',
            'password' => 'password123',
            'role' => 'client',
        ])
        ->assertCreated();
});

test('a non-admin cannot create facilities or kiosk devices', function () {
    $clinic = capacityClinic([Feature::MaxFacilities->value => 5, Feature::MaxKioskDevices->value => 5]);

    $staff = User::factory()->create(['role' => 'staff', 'tenant_id' => $clinic['tenant']->id]);

    $this->actingAs($staff, 'sanctum')
        ->postJson('/api/facilities', ['name' => 'X', 'code' => 'X'])
        ->assertForbidden();

    $this->actingAs($staff, 'sanctum')
        ->postJson('/api/kiosk-devices', ['name' => 'X'])
        ->assertForbidden();
});
