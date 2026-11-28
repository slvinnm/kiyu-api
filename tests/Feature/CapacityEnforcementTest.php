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
use App\Models\UserFacilityAssignment;
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

    $admin = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => $tenant->id]);

    // Device management runs in the Tenant workspace (facility admin).
    $ops = User::factory()->create(['role' => 'facility_admin', 'tenant_id' => $tenant->id]);
    UserFacilityAssignment::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $ops->id,
        'facility_id' => $facility->id,
    ]);

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

    return compact('tenant', 'facility', 'admin', 'ops', 'department');
}

test('a tenant cannot create a kiosk device beyond its limit', function () {
    $tenant = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk 1'])
        ->assertCreated()
        ->assertJsonPath('data.device.name', 'Kiosk 1')
        ->assertJsonStructure(['data' => ['device', 'pairing_code']]);

    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk 2'])
        ->assertStatus(409);

    $this->assertDatabaseCount('kiosk_devices', 1);
});

test('the pairing code is returned once and never persisted in plain text', function () {
    $tenant = capacityClinic([Feature::MaxKioskDevices->value => 2]);

    $pairingCode = $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk'])
        ->json('data.pairing_code');

    expect($pairingCode)->toBeString()->not->toBeEmpty();

    $stored = KioskDevice::query()->firstOrFail();
    expect($stored->pairing_code_hash)->toBe(hash('sha256', $pairingCode))
        ->and($stored->getAttribute('pairing_code_hash'))->not->toBe($pairingCode);

    // The list endpoint never exposes the hashes or the code.
    $this->actingAs($tenant['ops'], 'sanctum')
        ->getJson('/api/tenant/kiosk-devices')
        ->assertOk()
        ->assertJsonMissingPath('data.0.credential_hash')
        ->assertJsonMissingPath('data.0.pairing_code_hash');
});

test('a device redeems its pairing code for a credential exactly once', function () {
    $tenant = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $pairingCode = $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk'])
        ->json('data.pairing_code');

    $credential = $this->postJson('/api/tenant/devices/kiosk/pair', ['pairing_code' => $pairingCode])
        ->assertOk()
        ->assertJsonPath('data.device.tenant.slug', $tenant['tenant']->slug)
        ->json('data.credential');

    expect($credential)->toBeString()->not->toBeEmpty();

    $stored = KioskDevice::query()->firstOrFail();
    expect($stored->credential_hash)->toBe(hash('sha256', $credential))
        ->and($stored->pairing_code_hash)->toBeNull();

    // The code is burned: a replay is rejected.
    $this->postJson('/api/tenant/devices/kiosk/pair', ['pairing_code' => $pairingCode])
        ->assertNotFound();
});

test('a revoked kiosk device cannot resolve a tenant', function () {
    $tenant = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $pairingCode = $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk'])
        ->json('data.pairing_code');

    $credential = $this->postJson('/api/tenant/devices/kiosk/pair', ['pairing_code' => $pairingCode])
        ->json('data.credential');

    $deviceId = KioskDevice::query()->firstOrFail()->id;

    $this->actingAs($tenant['ops'], 'sanctum')
        ->deleteJson("/api/tenant/kiosk-devices/{$deviceId}")
        ->assertOk();

    // A revoked device is rejected on the kiosk intake route.
    $this->withHeader('X-Kiosk-Token', $credential)
        ->postJson('/api/tenant/intake/kiosk', ['department_id' => $tenant['department']->id])
        ->assertForbidden();
});

test('an unpaired device cannot authenticate with its placeholder credential', function () {
    $tenant = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    // Register but never redeem the pairing code.
    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk'])
        ->assertCreated();

    $device = KioskDevice::query()->firstOrFail();
    expect($device->paired_at)->toBeNull()
        ->and($device->isUsable())->toBeFalse();

    // No credential was ever issued, so the kiosk route rejects any token.
    $this->withHeader('X-Kiosk-Token', 'anything')
        ->postJson('/api/tenant/intake/kiosk', ['department_id' => $tenant['department']->id])
        ->assertForbidden();
});

test('the device list reports pairing state but never exposes hashes', function () {
    $tenant = capacityClinic([Feature::MaxKioskDevices->value => 2]);

    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Awaiting'])
        ->assertCreated();

    $this->actingAs($tenant['ops'], 'sanctum')
        ->getJson('/api/tenant/kiosk-devices')
        ->assertOk()
        ->assertJsonPath('data.0.is_paired', false)
        ->assertJsonPath('data.0.is_awaiting_pairing', true)
        ->assertJsonMissingPath('data.0.credential_hash')
        ->assertJsonMissingPath('data.0.pairing_code_hash');
});

test('a pairing code cannot be reissued for an already-paired device', function () {
    $tenant = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $pairingCode = $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk'])
        ->json('data.pairing_code');

    $this->postJson('/api/tenant/devices/kiosk/pair', ['pairing_code' => $pairingCode])->assertOk();

    $deviceId = KioskDevice::query()->firstOrFail()->id;

    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson("/api/tenant/kiosk-devices/{$deviceId}/pairing-code")
        ->assertStatus(409);
});

test('a pairing code can be reissued for a device that has not paired', function () {
    $tenant = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $firstCode = $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk'])
        ->json('data.pairing_code');

    $deviceId = KioskDevice::query()->firstOrFail()->id;

    $secondCode = $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson("/api/tenant/kiosk-devices/{$deviceId}/pairing-code")
        ->assertOk()
        ->json('data.pairing_code');

    expect($secondCode)->not->toBe($firstCode);

    // The superseded code no longer works; the new one does.
    $this->postJson('/api/tenant/devices/kiosk/pair', ['pairing_code' => $firstCode])->assertNotFound();

    $this->postJson('/api/tenant/devices/kiosk/pair', ['pairing_code' => $secondCode])->assertOk();
});

test('a tenant cannot create a facility beyond its limit', function () {
    $tenant = capacityClinic([Feature::MaxFacilities->value => 1]);

    // The onboarding facility already counts as 1.
    $this->actingAs($tenant['admin'], 'sanctum')
        ->postJson('/api/app/facilities', ['name' => 'Second', 'code' => 'SECOND'])
        ->assertStatus(409);

    $this->assertDatabaseCount('facilities', 1);
});

test('a tenant within its facility limit can add a facility', function () {
    $tenant = capacityClinic([Feature::MaxFacilities->value => 2]);

    $this->actingAs($tenant['admin'], 'sanctum')
        ->postJson('/api/app/facilities', ['name' => 'Second', 'code' => 'SECOND'])
        ->assertCreated()
        ->assertJsonPath('data.code', 'SECOND');

    $this->assertDatabaseCount('facilities', 2);
});

test('a tenant cannot create staff beyond its seat limit', function () {
    // admin + facility_admin fixture accounts already use 2 seats.
    $tenant = capacityClinic([Feature::MaxStaff->value => 3]);

    $this->actingAs($tenant['admin'], 'sanctum')
        ->postJson('/api/app/users', [
            'name' => 'Staff One',
            'email' => 'staff1@cap.test',
            'password' => 'password123',
            'role' => 'staff',
        ])
        ->assertCreated();

    // The next staff would exceed 3.
    $this->actingAs($tenant['admin'], 'sanctum')
        ->postJson('/api/app/users', [
            'name' => 'Staff Two',
            'email' => 'staff2@cap.test',
            'password' => 'password123',
            'role' => 'staff',
        ])
        ->assertStatus(409);
});

test('creating a client account does not consume a staff seat', function () {
    $tenant = capacityClinic([Feature::MaxStaff->value => 1]);

    // At the seat limit (admin only), but a client role must still be creatable.
    $this->actingAs($tenant['admin'], 'sanctum')
        ->postJson('/api/app/users', [
            'name' => 'Client User',
            'email' => 'clientuser@cap.test',
            'password' => 'password123',
            'role' => 'customer',
        ])
        ->assertCreated();
});

test('a non-admin cannot create facilities or kiosk devices', function () {
    $tenant = capacityClinic([Feature::MaxFacilities->value => 5, Feature::MaxKioskDevices->value => 5]);

    $staff = User::factory()->create(['role' => 'staff', 'tenant_id' => $tenant['tenant']->id]);

    $this->actingAs($staff, 'sanctum')
        ->postJson('/api/app/facilities', ['name' => 'X', 'code' => 'X'])
        ->assertForbidden();

    $this->actingAs($staff, 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'X'])
        ->assertForbidden();
});

test('revoking a kiosk device frees its quota slot', function () {
    $tenant = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $deviceId = $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk 1'])
        ->assertCreated()
        ->json('data.device.id');

    // At the limit: a second device is refused.
    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk 2'])
        ->assertStatus(409);

    // Revoke the first, then the slot is free.
    $this->actingAs($tenant['ops'], 'sanctum')
        ->deleteJson("/api/tenant/kiosk-devices/{$deviceId}")
        ->assertOk();

    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk 2'])
        ->assertCreated();

    // The revoked row is preserved for audit; only the active count dropped.
    $this->assertDatabaseCount('kiosk_devices', 2);
    $this->assertDatabaseHas('kiosk_devices', ['id' => $deviceId, 'is_active' => false]);
});

test('an expired kiosk device does not consume quota', function () {
    $tenant = capacityClinic([Feature::MaxKioskDevices->value => 1]);

    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', [
            'name' => 'Kiosk 1',
            'expires_at' => now()->addMinute()->toIso8601String(),
        ])
        ->assertCreated();

    // Fast-forward past its expiry.
    $this->travel(2)->minutes();

    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk 2'])
        ->assertCreated();
});

test('kiosk and display quotas are independent', function () {
    $tenant = capacityClinic([
        Feature::MaxKioskDevices->value => 1,
        Feature::MaxDisplayDevices->value => 1,
    ]);

    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/kiosk-devices', ['name' => 'Kiosk 1'])
        ->assertCreated();

    // The display limit is separate and still free.
    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/display-devices', ['name' => 'Display 1'])
        ->assertCreated();

    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/display-devices', ['name' => 'Display 2'])
        ->assertStatus(409);
});

test('revoking a display device frees its quota slot', function () {
    $tenant = capacityClinic([Feature::MaxDisplayDevices->value => 1]);

    $displayId = $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/display-devices', ['name' => 'Display 1'])
        ->assertCreated()
        ->json('data.device.id');

    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/display-devices', ['name' => 'Display 2'])
        ->assertStatus(409);

    $this->actingAs($tenant['ops'], 'sanctum')
        ->deleteJson("/api/tenant/display-devices/{$displayId}")
        ->assertOk();

    $this->actingAs($tenant['ops'], 'sanctum')
        ->postJson('/api/tenant/display-devices', ['name' => 'Display 2'])
        ->assertCreated();
});
