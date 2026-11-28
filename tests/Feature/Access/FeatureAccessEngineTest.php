<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Models\Facility;
use App\Models\Feature as FeatureRecord;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlement;
use App\Models\Tenant;
use App\Services\Access\FeatureAccessEngine;
use App\Services\Access\ReasonCode;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A tenant on a plan whose snapshot grants the given entitlements.
 *
 * @param  array<string, mixed>  $snapshot  feature_code => value
 * @return array{tenant: Tenant, subscription: Subscription}
 */
function engineTenant(array $snapshot = [], string $status = 'ACTIVE'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Engine Clinic',
        'slug' => 'engine-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $plan = Plan::query()->create(['code' => 'E-' . uniqid(), 'name' => 'E', 'is_active' => true]);
    $subscription = app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);
    $subscription->update(['status' => $status]);

    foreach ($snapshot as $code => $value) {
        SubscriptionEntitlement::query()->create([
            'subscription_id' => $subscription->id,
            'feature_code' => $code,
            'value' => $value,
        ]);
    }

    return ['tenant' => $tenant->fresh(), 'subscription' => $subscription->fresh()];
}

test('an entitled, released feature is allowed', function () {
    $ctx = engineTenant([
        Feature::ReportQueueOverview->value => null,
        Feature::QueueManagement->value => null,
    ]);

    $decision = app(FeatureAccessEngine::class)->canAccess($ctx['tenant'], Feature::ReportQueueOverview);

    expect($decision->allowed)->toBeTrue()
        ->and($decision->reasonCode)->toBe(ReasonCode::Allowed)
        ->and($decision->isFeatureReleased)->toBeTrue()
        ->and($decision->isEntitled)->toBeTrue();
});

test('a feature without an entitlement is denied', function () {
    $ctx = engineTenant([Feature::QueueManagement->value => null]);

    $decision = app(FeatureAccessEngine::class)->canAccess($ctx['tenant'], Feature::ReportPeakHours);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe(ReasonCode::EntitlementRequired);
});

test('releasing a feature globally to false denies it even when entitled', function () {
    $ctx = engineTenant([
        Feature::ReportQueueOverview->value => null,
        Feature::QueueManagement->value => null,
    ]);

    FeatureRecord::query()->create([
        'code' => Feature::ReportQueueOverview->value,
        'name' => 'Queue Overview',
        'category' => 'report',
        'value_type' => 'BOOLEAN',
        'is_feature_released' => false,
    ]);

    $decision = app(FeatureAccessEngine::class)->canAccess($ctx['tenant'], Feature::ReportQueueOverview);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe(ReasonCode::FeatureNotReleased)
        ->and($decision->isEntitled)->toBeFalse();
});

test('a report requires its queue_management dependency', function () {
    $ctx = engineTenant([Feature::ReportQueueOverview->value => null]);

    $decision = app(FeatureAccessEngine::class)->canAccess($ctx['tenant'], Feature::ReportQueueOverview);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe(ReasonCode::FeatureDependencyMissing);
});

test('a tenant with no granting subscription is denied', function () {
    $ctx = engineTenant();

    // End the period so no granting subscription remains.
    $ctx['subscription']->update(['current_period_end' => now()->subDay()]);

    $decision = app(FeatureAccessEngine::class)->canAccess($ctx['tenant']->fresh(), Feature::QueueManagement);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe(ReasonCode::SubscriptionRequired);
});

test('a past-due subscription grants nothing', function () {
    $ctx = engineTenant([Feature::QueueManagement->value => null], status: 'PAST_DUE');

    $decision = app(FeatureAccessEngine::class)->canAccess($ctx['tenant']->fresh(), Feature::QueueManagement);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe(ReasonCode::SubscriptionRequired);
});

test('a suspended tenant is denied before any entitlement check', function () {
    $ctx = engineTenant([Feature::QueueManagement->value => null]);
    $ctx['tenant']->update(['status' => TenantStatus::Suspended]);

    $decision = app(FeatureAccessEngine::class)->canAccess($ctx['tenant']->fresh(), Feature::QueueManagement);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe(ReasonCode::TenantInactive);
});

test('a missing registry row fails closed once the registry is populated', function () {
    $ctx = engineTenant([Feature::QueueManagement->value => null]);

    // Register a different feature so the registry is non-empty but ours is absent.
    FeatureRecord::query()->create([
        'code' => Feature::ReportPeakHours->value,
        'name' => 'Peak Hours',
        'category' => 'report',
        'value_type' => 'BOOLEAN',
        'is_feature_released' => true,
    ]);

    $decision = app(FeatureAccessEngine::class)->canAccess($ctx['tenant'], Feature::QueueManagement);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe(ReasonCode::FeatureNotRegistered);
});

test('capacity remaining reflects usage', function () {
    $ctx = engineTenant([Feature::MaxFacilities->value => ['limit' => 3]]);

    $decision = app(FeatureAccessEngine::class)->canAccess($ctx['tenant'], Feature::MaxFacilities);

    expect($decision->allowed)->toBeTrue()
        ->and($decision->limit)->toBe(3)
        ->and($decision->used)->toBe(1)
        ->and($decision->remaining)->toBe(2);
});
