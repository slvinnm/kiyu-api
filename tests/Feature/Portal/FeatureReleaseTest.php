<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Models\Facility;
use App\Models\Feature as FeatureRecord;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use App\Services\Access\FeatureAccessEngine;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A tenant on a plan that grants the queue feature and a released registry.
 *
 * @return array{tenant: Tenant, facility: Facility, admin: User, platform: User}
 */
function releaseFixture(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Release Clinic',
        'slug' => 'release-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create(['role' => 'facility_admin', 'tenant_id' => $tenant->id]);
    UserFacilityAssignment::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $admin->id,
        'facility_id' => $facility->id,
    ]);

    $platform = User::factory()->create(['role' => 'platform_admin', 'tenant_id' => null]);

    $plan = Plan::query()->create(['code' => 'R-' . uniqid(), 'name' => 'R', 'is_active' => true]);
    $plan->entitlements()->create(['feature_code' => Feature::QueueManagement->value, 'value' => null]);
    app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);

    // Seed the registry row so the release switch is authoritative.
    FeatureRecord::query()->create([
        'code' => Feature::QueueManagement->value,
        'name' => 'Queue Management',
        'category' => 'core',
        'value_type' => 'BOOLEAN',
        'is_feature_released' => true,
    ]);

    return compact('tenant', 'facility', 'admin', 'platform');
}

test('a platform admin can list and toggle the feature release', function () {
    $f = releaseFixture();

    $this->asContext('portal')->actingAs($f['platform'], 'sanctum')
        ->getJson('/api/portal/features')
        ->assertOk()
        ->assertJsonFragment(['code' => Feature::QueueManagement->value]);

    $this->asContext('portal')->actingAs($f['platform'], 'sanctum')
        ->patchJson('/api/portal/features/' . Feature::QueueManagement->value . '/release', [
            'is_feature_released' => false,
            'release_note' => 'Temporary shutdown',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_feature_released', false);

    $this->assertDatabaseHas('audit_logs', ['action' => 'platform.feature.release_changed']);
});

test('releasing a feature to false denies it even when entitled', function () {
    $f = releaseFixture();

    // Kiosk intake needs queue_management via the release+entitlement gate; the
    // route itself uses no feature gate, so exercise the engine through a
    // feature-gated report instead is out of scope here. Assert the engine:
    $decision = app(FeatureAccessEngine::class)
        ->canAccess($f['tenant'], Feature::QueueManagement);
    expect($decision->allowed)->toBeTrue();

    FeatureRecord::query()->where('code', Feature::QueueManagement->value)
        ->update(['is_feature_released' => false]);

    $decision = app(FeatureAccessEngine::class)
        ->canAccess($f['tenant']->fresh(), Feature::QueueManagement);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode->value)->toBe('FEATURE_NOT_RELEASED');
});

test('a tenant admin cannot change a feature release', function () {
    $f = releaseFixture();
    $tenantAdmin = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => $f['tenant']->id]);

    $this->asContext('portal')->actingAs($tenantAdmin, 'sanctum')
        ->patchJson('/api/portal/features/' . Feature::QueueManagement->value . '/release', [
            'is_feature_released' => true,
        ])
        ->assertForbidden();
});
