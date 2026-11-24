<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Facility;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, facility: Facility, admin: User, plan: Plan}
 */
function billingClinic(string $slug = 'billing-clinic'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Billing Clinic',
        'slug' => $slug,
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Billing Facility',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'tenant_id' => $tenant->id,
    ]);

    $plan = Plan::query()->create([
        'code' => 'STANDARD',
        'name' => 'Standard',
        'is_active' => true,
    ]);

    $plan->prices()->create([
        'billing_interval' => 'MONTHLY',
        'amount' => 150_000_00,
        'currency' => 'IDR',
        'provider' => '',
    ]);

    $plan->entitlements()->create([
        'feature_code' => Feature::QueueManagement->value,
        'value' => null,
    ]);

    $plan->entitlements()->create([
        'feature_code' => Feature::MaxKioskDevices->value,
        'value' => ['limit' => 2],
    ]);

    return compact('tenant', 'facility', 'admin', 'plan');
}

test('starting a trial creates a trialing subscription', function () {
    $clinic = billingClinic();

    $subscription = app(SubscriptionService::class)->start(
        tenant: $clinic['tenant'],
        plan: $clinic['plan'],
        interval: BillingInterval::Monthly,
        trialDays: 14,
    );

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at)->not->toBeNull()
        ->and($subscription->isEffective())->toBeTrue()
        ->and($subscription->plan_price_id)->not->toBeNull();
});

test('only one subscription is ever effective per tenant', function () {
    $clinic = billingClinic();
    $service = app(SubscriptionService::class);

    $first = $service->start($clinic['tenant'], $clinic['plan'], BillingInterval::Monthly, 7);
    $second = $service->start($clinic['tenant'], $clinic['plan'], BillingInterval::Monthly);

    $effective = Subscription::query()
        ->where('tenant_id', $clinic['tenant']->id)
        ->whereIn('status', SubscriptionStatus::effectiveValues())
        ->count();

    expect($effective)->toBe(1)
        ->and($first->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($second->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

test('a subscription without a trial is not effective until it is paid', function () {
    $clinic = billingClinic();

    $subscription = app(SubscriptionService::class)->start(
        $clinic['tenant'],
        $clinic['plan'],
        BillingInterval::Monthly,
    );

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->isEffective())->toBeFalse()
        ->and($subscription->current_period_start)->toBeNull();
});

test('an expired trial is not effective', function () {
    $clinic = billingClinic();
    $service = app(SubscriptionService::class);

    $subscription = $service->start($clinic['tenant'], $clinic['plan'], BillingInterval::Monthly, 1);

    $subscription->update(['trial_ends_at' => now()->subDay()]);

    expect($subscription->fresh()->isEffective())->toBeFalse();
});

test('hasFeature reflects the plan entitlements', function () {
    $clinic = billingClinic();
    $service = app(SubscriptionService::class);

    $service->start($clinic['tenant'], $clinic['plan'], BillingInterval::Monthly, 30);

    expect($service->hasFeature($clinic['tenant'], Feature::QueueManagement))->toBeTrue()
        ->and($service->hasFeature($clinic['tenant'], Feature::AdvancedReports))->toBeFalse();
});

test('a limit entitlement reports its cap and usage', function () {
    $clinic = billingClinic();
    $service = app(SubscriptionService::class);

    // The fixture plan grants max_kiosk_devices: 2.
    $service->start($clinic['tenant'], $clinic['plan'], BillingInterval::Monthly, 30);

    $limit = $service->limit($clinic['tenant'], Feature::MaxKioskDevices);

    expect($limit)->toBe(2)
        ->and($service->usage($clinic['tenant'], Feature::MaxKioskDevices))->toBe(0)
        ->and($service->hasCapacityFor($clinic['tenant'], Feature::MaxKioskDevices))->toBeTrue();
});

test('a tenant admin can view and start a subscription over the API', function () {
    $clinic = billingClinic();

    $this->actingAs($clinic['admin'], 'sanctum')
        ->getJson('/api/billing/subscription')
        ->assertOk()
        ->assertJsonPath('data.subscription', null);

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/billing/subscription', [
            'plan_code' => 'STANDARD',
            'billing_interval' => 'MONTHLY',
            'trial_days' => 14,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', SubscriptionStatus::Trialing->value)
        ->assertJsonPath('data.plan.code', 'STANDARD');

    $this->actingAs($clinic['admin'], 'sanctum')
        ->getJson('/api/billing/subscription')
        ->assertOk()
        ->assertJsonPath('data.is_effective', true);
});

test('starting a paid plan over the API does not activate it without payment', function () {
    $clinic = billingClinic();

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/billing/subscription', [
            'plan_code' => 'STANDARD',
            'billing_interval' => 'MONTHLY',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', SubscriptionStatus::PastDue->value)
        ->assertJsonPath('data.is_effective', false)
        ->assertJsonPath('data.payment_required', true);

    expect(app(SubscriptionService::class)->hasFeature($clinic['tenant'], Feature::QueueManagement))
        ->toBeFalse();
});

test('a non-admin tenant user cannot start a subscription', function () {
    $clinic = billingClinic();

    $staff = User::factory()->create([
        'role' => 'staff',
        'tenant_id' => $clinic['tenant']->id,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->postJson('/api/billing/subscription', [
            'plan_code' => 'STANDARD',
            'billing_interval' => 'MONTHLY',
        ])
        ->assertForbidden();
});

test('the platform can list and create plans', function () {
    $platform = User::factory()->create([
        'role' => 'platform_admin',
        'tenant_id' => null,
    ]);

    $this->actingAs($platform, 'sanctum')
        ->postJson('/api/platform/plans', [
            'code' => 'PRO',
            'name' => 'Pro',
            'prices' => [
                ['billing_interval' => 'MONTHLY', 'amount' => 25000000, 'currency' => 'idr'],
            ],
            'entitlements' => [
                ['feature_code' => 'queue_management'],
                ['feature_code' => 'max_facilities', 'limit' => 3],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'PRO')
        ->assertJsonPath('data.prices.0.currency', 'IDR');

    $plan = Plan::query()->where('code', 'PRO')->firstOrFail();
    $limit = $plan->entitlements()->where('feature_code', 'max_facilities')->firstOrFail();

    expect($limit->value)->toBe(['limit' => 3])
        ->and($plan->entitlements()->where('feature_code', 'queue_management')->firstOrFail()->value)->toBeNull();

    $this->actingAs($platform, 'sanctum')
        ->getJson('/api/platform/plans')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'PRO');
});

test('the expiry command ends lapsed trials and paid periods', function () {
    $clinic = billingClinic();
    $service = app(SubscriptionService::class);

    $trial = $service->start($clinic['tenant'], $clinic['plan'], BillingInterval::Monthly, 7);
    $trial->update(['trial_ends_at' => now()->subMinute()]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($trial->fresh()->isEffective())->toBeFalse();
});

test('the expiry command cancels a subscription at its period end', function () {
    $clinic = billingClinic();
    $service = app(SubscriptionService::class);

    $trial = $service->start($clinic['tenant'], $clinic['plan'], BillingInterval::Monthly, 7);
    $service->cancelAtPeriodEnd($clinic['tenant']);

    // Still inside the period: nothing happens yet.
    $this->artisan('subscriptions:expire')->assertSuccessful();
    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Trialing);

    // Past the period: the cancellation takes effect.
    $trial->update(['trial_ends_at' => now()->subMinute()]);
    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($trial->fresh()->cancelled_at)->not->toBeNull();
});
