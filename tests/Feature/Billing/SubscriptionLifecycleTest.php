<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Facility;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, plan: Plan}
 */
function lifecycleTenant(array $entitlements = [Feature::QueueManagement]): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Lifecycle Clinic',
        'slug' => 'lifecycle-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $plan = Plan::query()->create(['code' => 'L-' . uniqid(), 'name' => 'L', 'is_active' => true]);
    $plan->prices()->create(['billing_interval' => 'MONTHLY', 'amount' => 100_000, 'currency' => 'IDR', 'provider' => '', 'is_active' => true]);

    foreach ($entitlements as $feature) {
        $plan->entitlements()->create(['feature_code' => $feature->value, 'value' => null]);
    }

    return compact('tenant', 'plan');
}

test('a paid checkout keeps the current subscription granting until activated', function () {
    ['tenant' => $tenant, 'plan' => $plan] = lifecycleTenant();
    $service = app(SubscriptionService::class);

    $trial = $service->start($tenant, $plan, BillingInterval::Monthly, 14);

    // Starting a paid plan does not cancel the trial.
    $paid = $service->start($tenant, $plan, BillingInterval::Monthly);

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Trialing)
        ->and($paid->fresh()->status)->toBe(SubscriptionStatus::PendingPayment)
        ->and($service->grantingSubscription($tenant)->id)->toBe($trial->id);

    $service->activate($paid);

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($paid->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($service->grantingSubscription($tenant)->id)->toBe($paid->id);
});

test('activation snapshots the plan entitlements', function () {
    ['tenant' => $tenant, 'plan' => $plan] = lifecycleTenant([Feature::QueueManagement, Feature::ReportQueueOverview]);

    $subscription = app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly);
    expect($subscription->entitlements()->count())->toBe(0);

    app(SubscriptionService::class)->activate($subscription);

    expect($subscription->fresh()->entitlements()->pluck('feature_code')->sort()->values()->all())
        ->toBe([Feature::QueueManagement->value, Feature::ReportQueueOverview->value]);
});

test('editing the plan catalogue after activation does not change the snapshot', function () {
    ['tenant' => $tenant, 'plan' => $plan] = lifecycleTenant([Feature::QueueManagement]);

    $subscription = app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly);
    app(SubscriptionService::class)->activate($subscription);

    // Remove the entitlement from the catalogue.
    $plan->entitlements()->delete();

    // The snapshot still grants it.
    expect($subscription->fresh()->entitlements()->where('feature_code', Feature::QueueManagement->value)->exists())
        ->toBeTrue();
});

test('a second trial is refused', function () {
    ['tenant' => $tenant, 'plan' => $plan] = lifecycleTenant();
    $service = app(SubscriptionService::class);

    $first = $service->start($tenant, $plan, BillingInterval::Monthly, 14);
    $first->update(['status' => SubscriptionStatus::Expired]);

    expect(fn() => $service->start($tenant, $plan, BillingInterval::Monthly, 14))
        ->toThrow(InvalidStateException::class);
});

test('a failed payment leaves the previous subscription active', function () {
    ['tenant' => $tenant, 'plan' => $plan] = lifecycleTenant();
    $service = app(SubscriptionService::class);

    $trial = $service->start($tenant, $plan, BillingInterval::Monthly, 14);
    $service->start($tenant, $plan, BillingInterval::Monthly); // pending, never activated

    expect($service->grantingSubscription($tenant)->id)->toBe($trial->id)
        ->and(Subscription::query()->where('tenant_id', $tenant->id)->where('status', SubscriptionStatus::Active->value)->exists())
        ->toBeFalse();
});
