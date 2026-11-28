<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Models\Addon;
use App\Models\Facility;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Access\FeatureAccessEngine;
use App\Services\Billing\AddonPurchaseService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, admin: User, plan: Plan, addon: Addon, subscription: Subscription}
 */
function addonClinic(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Addon Clinic',
        'slug' => 'addon-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => $tenant->id]);

    $plan = Plan::query()->create(['code' => 'A-' . uniqid(), 'name' => 'A', 'is_active' => true]);
    $plan->prices()->create(['billing_interval' => 'MONTHLY', 'amount' => 100_000, 'currency' => 'IDR', 'provider' => '', 'is_active' => true]);
    $plan->entitlements()->create(['feature_code' => Feature::QueueManagement->value, 'value' => null]);

    $subscription = app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);

    $addon = Addon::query()->create(['code' => 'ADDON_REPORT_PEAK_HOURS', 'name' => 'Peak Hours', 'category' => 'report', 'is_active' => true]);
    $addon->entitlements()->create(['feature_code' => Feature::ReportPeakHours->value, 'value' => null]);
    $addon->prices()->create(['billing_interval' => 'MONTHLY', 'amount' => 50_000, 'currency' => 'IDR', 'provider' => '', 'is_active' => true]);
    $addon->eligibilities()->create(['plan_id' => $plan->id]);

    return compact('tenant', 'admin', 'plan', 'addon', 'subscription') + ['facility' => $facility];
}

test('a plan can list and buy an eligible add-on', function () {
    $c = addonClinic();

    $this->actingAs($c['admin'], 'sanctum')->asContext('app')
        ->getJson('/api/app/billing/addons')
        ->assertOk()
        ->assertJsonFragment(['code' => 'ADDON_REPORT_PEAK_HOURS']);

    $this->actingAs($c['admin'], 'sanctum')->asContext('app')
        ->postJson('/api/app/billing/addons', ['addon_id' => $c['addon']->id])
        ->assertCreated()
        ->assertJsonPath('data.status', 'PENDING_PAYMENT');
});

test('a pending add-on grants nothing until activated', function () {
    $c = addonClinic();

    $purchase = app(AddonPurchaseService::class)->purchase($c['tenant'], $c['addon']);

    $engine = app(FeatureAccessEngine::class);
    expect($engine->canAccess($c['tenant'], Feature::ReportPeakHours)->allowed)->toBeFalse();

    app(AddonPurchaseService::class)->activate($purchase);

    expect($engine->canAccess($c['tenant']->fresh(), Feature::ReportPeakHours)->allowed)->toBeTrue();
});

test('an ineligible add-on is refused', function () {
    $c = addonClinic();

    $otherPlan = Plan::query()->create(['code' => 'OTHER-' . uniqid(), 'name' => 'Other', 'is_active' => true]);

    // Move eligibility away from the tenant's plan.
    $c['addon']->eligibilities()->delete();
    $c['addon']->eligibilities()->create(['plan_id' => $otherPlan->id]);

    $this->actingAs($c['admin'], 'sanctum')->asContext('app')
        ->postJson('/api/app/billing/addons', ['addon_id' => $c['addon']->id])
        ->assertStatus(422);
});

test('cancelling an add-on schedules it at period end', function () {
    $c = addonClinic();

    $purchase = app(AddonPurchaseService::class)->purchase($c['tenant'], $c['addon']);
    app(AddonPurchaseService::class)->activate($purchase);

    $this->actingAs($c['admin'], 'sanctum')->asContext('app')
        ->postJson("/api/app/billing/addons/{$purchase->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.cancel_at_period_end', true);
});

test('platform admin can manage the add-on catalogue', function () {
    $c = addonClinic();
    $platform = User::factory()->create(['role' => 'platform_admin', 'tenant_id' => null]);

    $this->asContext('portal')->actingAs($platform, 'sanctum')
        ->getJson('/api/portal/addons')
        ->assertOk()
        ->assertJsonFragment(['code' => 'ADDON_REPORT_PEAK_HOURS']);

    $id = $this->asContext('portal')->actingAs($platform, 'sanctum')
        ->postJson('/api/portal/addons', [
            'code' => 'ADDON_KIOSK_1',
            'name' => 'Kiosk Pack',
            'category' => 'capacity',
            'entitlements' => [['feature_code' => 'max_kiosk_devices', 'limit' => 1]],
        ])
        ->assertCreated()
        ->json('data.id');

    $this->asContext('portal')->actingAs($platform, 'sanctum')
        ->postJson("/api/portal/addons/{$id}/prices", ['billing_interval' => 'MONTHLY', 'amount' => 20_000, 'currency' => 'IDR'])
        ->assertCreated();

    $this->asContext('portal')->actingAs($platform, 'sanctum')
        ->putJson("/api/portal/addons/{$id}/eligibilities", ['plan_ids' => [$c['plan']->id]])
        ->assertOk();
});
