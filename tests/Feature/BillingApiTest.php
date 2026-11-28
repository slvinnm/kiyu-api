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
function billingTenant(string $slug = 'billing-tenant'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Billing Organization',
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
        'role' => 'tenant_admin',
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
    $tenant = billingTenant();

    $subscription = app(SubscriptionService::class)->start(
        tenant: $tenant['tenant'],
        plan: $tenant['plan'],
        interval: BillingInterval::Monthly,
        trialDays: 14,
    );

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at)->not->toBeNull()
        ->and($subscription->isEffective())->toBeTrue()
        ->and($subscription->plan_price_id)->not->toBeNull();
});

test('a new subscription does not cancel the current one until activation', function () {
    $tenant = billingTenant();
    $service = app(SubscriptionService::class);

    $first = $service->start($tenant['tenant'], $tenant['plan'], BillingInterval::Monthly, 7);
    $second = $service->start($tenant['tenant'], $tenant['plan'], BillingInterval::Monthly);

    // The paid checkout is pending and both rows exist; the trial keeps access.
    expect($first->fresh()->status)->toBe(SubscriptionStatus::Trialing)
        ->and($second->fresh()->status)->toBe(SubscriptionStatus::PendingPayment)
        ->and($service->grantingSubscription($tenant['tenant'])->id)->toBe($first->id);

    // Activating the paid subscription supersedes the trial.
    $service->activate($second);

    expect($first->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($second->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($service->grantingSubscription($tenant['tenant'])->id)->toBe($second->id);
});

test('a subscription without a trial is not effective until it is paid', function () {
    $tenant = billingTenant();

    $subscription = app(SubscriptionService::class)->start(
        $tenant['tenant'],
        $tenant['plan'],
        BillingInterval::Monthly,
    );

    expect($subscription->status)->toBe(SubscriptionStatus::PendingPayment)
        ->and($subscription->isEffective())->toBeFalse()
        ->and($subscription->current_period_start)->toBeNull();
});

test('an expired trial is not effective', function () {
    $tenant = billingTenant();
    $service = app(SubscriptionService::class);

    $subscription = $service->start($tenant['tenant'], $tenant['plan'], BillingInterval::Monthly, 1);

    $subscription->update(['trial_ends_at' => now()->subDay()]);

    expect($subscription->fresh()->isEffective())->toBeFalse();
});

test('hasFeature reflects the plan entitlements', function () {
    $tenant = billingTenant();
    $service = app(SubscriptionService::class);

    $service->start($tenant['tenant'], $tenant['plan'], BillingInterval::Monthly, 30);

    expect($service->hasFeature($tenant['tenant'], Feature::QueueManagement))->toBeTrue()
        ->and($service->hasFeature($tenant['tenant'], Feature::AdvancedReports))->toBeFalse();
});

test('a limit entitlement reports its cap and usage', function () {
    $tenant = billingTenant();
    $service = app(SubscriptionService::class);

    // The fixture plan grants max_kiosk_devices: 2.
    $service->start($tenant['tenant'], $tenant['plan'], BillingInterval::Monthly, 30);

    $limit = $service->limit($tenant['tenant'], Feature::MaxKioskDevices);

    expect($limit)->toBe(2)
        ->and($service->usage($tenant['tenant'], Feature::MaxKioskDevices))->toBe(0)
        ->and($service->hasCapacityFor($tenant['tenant'], Feature::MaxKioskDevices))->toBeTrue();
});

test('a tenant admin can view and start a paid subscription over the API', function () {
    $tenant = billingTenant();

    $this->actingAs($tenant['admin'], 'sanctum')
        ->getJson('/api/app/billing/subscription')
        ->assertOk()
        ->assertJsonPath('data.subscription', null);

    $this->actingAs($tenant['admin'], 'sanctum')
        ->postJson('/api/app/billing/subscription', [
            'plan_code' => 'STANDARD',
            'billing_interval' => 'MONTHLY',
            // A client can no longer request a trial; it is ignored/absent.
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', SubscriptionStatus::PendingPayment->value)
        ->assertJsonPath('data.plan.code', 'STANDARD');

    // Pending does not grant access.
    $this->actingAs($tenant['admin'], 'sanctum')
        ->getJson('/api/app/billing/subscription')
        ->assertOk()
        ->assertJsonPath('data.is_effective', false);
});

test('starting a paid plan over the API does not activate it without payment', function () {
    $tenant = billingTenant();

    $this->actingAs($tenant['admin'], 'sanctum')
        ->postJson('/api/app/billing/subscription', [
            'plan_code' => 'STANDARD',
            'billing_interval' => 'MONTHLY',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', SubscriptionStatus::PendingPayment->value)
        ->assertJsonPath('data.is_effective', false)
        ->assertJsonPath('data.payment_required', true);

    expect(app(SubscriptionService::class)->hasFeature($tenant['tenant'], Feature::QueueManagement))
        ->toBeFalse();
});

test('the API rejects a client-supplied trial duration', function () {
    $tenant = billingTenant();

    // `trial_days` is not part of the request contract; the body value is
    // ignored and the subscription is still a paid PENDING_PAYMENT checkout.
    $this->actingAs($tenant['admin'], 'sanctum')
        ->postJson('/api/app/billing/subscription', [
            'plan_code' => 'STANDARD',
            'billing_interval' => 'MONTHLY',
            'trial_days' => 365,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', SubscriptionStatus::PendingPayment->value);
});

test('a non-admin tenant user cannot start a subscription', function () {
    $tenant = billingTenant();

    $staff = User::factory()->create([
        'role' => 'staff',
        'tenant_id' => $tenant['tenant']->id,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->postJson('/api/app/billing/subscription', [
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
        ->postJson('/api/portal/plans', [
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
        ->getJson('/api/portal/plans')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'PRO');
});

test('the expiry command ends lapsed trials and paid periods', function () {
    $tenant = billingTenant();
    $service = app(SubscriptionService::class);

    $trial = $service->start($tenant['tenant'], $tenant['plan'], BillingInterval::Monthly, 7);
    $trial->update(['trial_ends_at' => now()->subMinute()]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($trial->fresh()->isEffective())->toBeFalse();
});

test('the expiry command cancels a subscription at its period end', function () {
    $tenant = billingTenant();
    $service = app(SubscriptionService::class);

    $trial = $service->start($tenant['tenant'], $tenant['plan'], BillingInterval::Monthly, 7);
    $service->cancelAtPeriodEnd($tenant['tenant']);

    // Still inside the period: nothing happens yet.
    $this->artisan('subscriptions:expire')->assertSuccessful();
    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Trialing);

    // Past the period: the cancellation takes effect.
    $trial->update(['trial_ends_at' => now()->subMinute()]);
    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($trial->fresh()->cancelled_at)->not->toBeNull();
});

test('the tenant can read resolved entitlements with advanced implying basic', function () {
    $tenant = billingTenant();

    // The fixture plan grants basic_reports + advanced_reports + limits.
    app(SubscriptionService::class)->start($tenant['tenant'], $tenant['plan'], BillingInterval::Monthly, 30);

    $this->actingAs($tenant['admin'], 'sanctum')
        ->getJson('/api/app/billing/entitlements')
        ->assertOk()
        ->assertJsonPath('data.queue_management', true)
        ->assertJsonPath('data.basic_reports', false)
        ->assertJsonPath('data.max_kiosk_devices', 2);
});

test('the tenant plan catalogue marks the current plan', function () {
    $tenant = billingTenant();
    app(SubscriptionService::class)->start($tenant['tenant'], $tenant['plan'], BillingInterval::Monthly, 30);

    $response = $this->actingAs($tenant['admin'], 'sanctum')
        ->getJson('/api/app/billing/plans')
        ->assertOk();

    expect($response->json('data.current_plan_id'))->toBe($tenant['plan']->id);
    expect($response->json('data.plans'))->not->toBeEmpty();
});

test('a plan without basic_reports cannot reach the reports summary', function () {
    $tenant = billingTenant();
    // Fixture plan grants queue_management only, not basic_reports.
    app(SubscriptionService::class)->start($tenant['tenant'], $tenant['plan'], BillingInterval::Monthly, 30);

    $this->actingAs($tenant['admin'], 'sanctum')
        ->getJson('/api/tenant/reports/summary')
        ->assertForbidden();
});

test('report_export alone does not grant access to the queue overview report', function () {
    $tenant = billingTenant();

    // Per-report gates replace the old "advanced implies basic" shortcut:
    // holding only the export entitlement must not expose report data.
    $tenant['plan']->entitlements()->create([
        'feature_code' => Feature::ReportExport->value,
        'value' => null,
    ]);

    app(SubscriptionService::class)->start($tenant['tenant'], $tenant['plan'], BillingInterval::Monthly, 30);

    $this->actingAs($tenant['admin'], 'sanctum')->asContext('app')
        ->getJson('/api/app/reports/summary')
        ->assertForbidden();
});
