<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Database\Seeder;

class BillingSeeder extends Seeder
{
    /**
     * Seed the plan catalogue.
     *
     * Prices are stored in the smallest currency unit (integer). Toggles are
     * granted by the presence of an entitlement row; limits carry a
     * `{"limit": n}` value.
     */
    public function run(): void
    {
        $this->plan(
            code: 'TRIAL',
            name: 'Trial',
            description: 'Time-limited trial of the core queue features.',
            prices: [],
            toggles: [Feature::QueueManagement],
            limits: [[Feature::MaxFacilities, 1], [Feature::MaxStaff, 5], [Feature::MaxKioskDevices, 1], [Feature::MaxDisplayDevices, 1]],
        );

        $this->plan(
            code: 'STANDARD',
            name: 'Standard',
            description: 'Core queue management for a single facility.',
            prices: [
                ['MONTHLY', 150_000_00],
                ['YEARLY', 1_500_000_00],
            ],
            toggles: [Feature::QueueManagement, Feature::BasicReports, Feature::WhatsAppNotifications],
            limits: [[Feature::MaxFacilities, 1], [Feature::MaxStaff, 10], [Feature::MaxKioskDevices, 2], [Feature::MaxDisplayDevices, 2]],
        );

        $this->plan(
            code: 'MULTI_FACILITY',
            name: 'Multi Facility',
            description: 'Multiple facilities, more staff and advanced reporting.',
            prices: [
                ['MONTHLY', 400_000_00],
                ['YEARLY', 4_000_000_00],
            ],
            toggles: [
                Feature::QueueManagement,
                Feature::BasicReports,
                Feature::AdvancedReports,
                Feature::WhatsAppNotifications,
            ],
            limits: [[Feature::MaxFacilities, 5], [Feature::MaxStaff, 50], [Feature::MaxKioskDevices, 10], [Feature::MaxDisplayDevices, 10]],
        );

        // Start the demo tenant on a trial so the billing endpoints and feature
        // checks have something to show.
        $tenant = Tenant::query()->where('slug', 'kiyu-demo')->first();
        $trial = Plan::query()->where('code', 'TRIAL')->first();

        if ($tenant !== null && $trial !== null && $tenant->currentSubscription === null) {
            app(SubscriptionService::class)->start(
                tenant: $tenant,
                plan: $trial,
                interval: BillingInterval::Monthly,
                trialDays: 14,
            );
        }
    }

    /**
     * Create or update a plan with its prices, toggles and limits.
     *
     * @param  list<array{0: string, 1: int}>  $prices
     * @param  list<Feature>  $toggles
     * @param  list<array{0: Feature, 1: int}>  $limits
     */
    private function plan(
        string $code,
        string $name,
        string $description,
        array $prices,
        array $toggles,
        array $limits,
    ): void {
        $plan = Plan::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'description' => $description, 'is_active' => true],
        );

        foreach ($prices as [$interval, $amount]) {
            $plan->prices()->updateOrCreate(
                ['billing_interval' => $interval, 'currency' => 'IDR', 'provider' => ''],
                ['amount' => $amount],
            );
        }

        foreach ($toggles as $feature) {
            $plan->entitlements()->updateOrCreate(
                ['feature_code' => $feature->value],
                ['value' => null],
            );
        }

        foreach ($limits as [$feature, $limit]) {
            $plan->entitlements()->updateOrCreate(
                ['feature_code' => $feature->value],
                ['value' => ['limit' => $limit]],
            );
        }
    }
}
