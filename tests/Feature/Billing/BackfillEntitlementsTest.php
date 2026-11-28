<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Models\Facility;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the backfill maps aggregate report codes to individual report codes', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Backfill Clinic',
        'slug' => 'backfill-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $plan = Plan::query()->create(['code' => 'B-' . uniqid(), 'name' => 'B', 'is_active' => true]);
    $plan->entitlements()->create(['feature_code' => 'queue_management', 'value' => null]);
    $plan->entitlements()->create(['feature_code' => 'basic_reports', 'value' => null]);
    $plan->entitlements()->create(['feature_code' => 'advanced_reports', 'value' => null]);
    $plan->entitlements()->create(['feature_code' => Feature::MaxFacilities->value, 'value' => ['limit' => 3]]);

    $subscription = app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);

    // A trial snapshots the individual codes immediately; clear so we exercise
    // the legacy mapping path.
    $subscription->entitlements()->delete();

    $this->artisan('billing:backfill-entitlements')->assertSuccessful();

    $codes = $subscription->fresh()->entitlements()->pluck('feature_code')->sort()->values()->all();

    expect($codes)->toContain('queue_management')
        ->toContain(Feature::ReportQueueOverview->value)
        ->toContain(Feature::ReportExport->value)
        ->toContain(Feature::MaxFacilities->value)
        ->not->toContain('basic_reports')
        ->not->toContain('advanced_reports');
});
