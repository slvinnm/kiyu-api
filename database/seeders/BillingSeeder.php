<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Database\Seeder;

class BillingSeeder extends Seeder
{
    /**
     * Seed a minimal plan catalogue.
     *
     * Prices are stored in the smallest currency unit (integer). The initial
     * release runs on trial/manual activation, so no payment provider row is
     * created yet.
     */
    public function run(): void
    {
        $trial = Plan::query()->firstOrCreate(
            ['code' => 'TRIAL'],
            [
                'name' => 'Trial',
                'description' => 'Time-limited trial with the core queue features.',
                'is_active' => true,
            ]
        );

        $trial->entitlements()->updateOrCreate(
            ['feature_code' => 'queue_management'],
            ['value' => ['enabled' => true]]
        );

        $standard = Plan::query()->firstOrCreate(
            ['code' => 'STANDARD'],
            [
                'name' => 'Standard',
                'description' => 'Core queue management for a single facility.',
                'is_active' => true,
            ]
        );

        $standard->prices()->updateOrCreate(
            ['billing_interval' => 'MONTHLY', 'currency' => 'IDR', 'provider' => ''],
            ['amount' => 150_000_00]
        );
        $standard->prices()->updateOrCreate(
            ['billing_interval' => 'YEARLY', 'currency' => 'IDR', 'provider' => ''],
            ['amount' => 1_500_000_00]
        );

        foreach (['queue_management', 'basic_reports'] as $feature) {
            $standard->entitlements()->updateOrCreate(
                ['feature_code' => $feature],
                ['value' => ['enabled' => true]]
            );
        }

        $multi = Plan::query()->firstOrCreate(
            ['code' => 'MULTI_FACILITY'],
            [
                'name' => 'Multi Facility',
                'description' => 'Multiple facilities and advanced reporting.',
                'is_active' => true,
            ]
        );

        $multi->prices()->updateOrCreate(
            ['billing_interval' => 'MONTHLY', 'currency' => 'IDR', 'provider' => ''],
            ['amount' => 400_000_00]
        );
        $multi->prices()->updateOrCreate(
            ['billing_interval' => 'YEARLY', 'currency' => 'IDR', 'provider' => ''],
            ['amount' => 4_000_000_00]
        );

        foreach (['queue_management', 'basic_reports', 'multi_facility', 'advanced_reports'] as $feature) {
            $multi->entitlements()->updateOrCreate(
                ['feature_code' => $feature],
                ['value' => ['enabled' => true]]
            );
        }

        // Start the demo tenant on a trial so the billing endpoints and feature
        // checks have something to show.
        $tenant = Tenant::query()->where('slug', 'kiyu-demo')->first();

        if ($tenant !== null && $tenant->currentSubscription === null) {
            app(SubscriptionService::class)->start(
                tenant: $tenant,
                plan: $trial,
                interval: BillingInterval::Monthly,
                trialDays: 14,
            );
        }
    }
}
