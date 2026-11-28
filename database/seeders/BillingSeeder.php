<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Database\Seeder;

/**
 * Plan catalogue: Trial, Essential, Growth, Enterprise.
 *
 * Report entitlements are individual (`report_*`), not the legacy aggregate
 * toggles. WhatsApp is not bundled into any plan; it is sold as a quota add-on.
 * Prices are catalogue data only; the final commercial values are not set here
 * for Enterprise, which is seeded without a public price until validated.
 */
class BillingSeeder extends Seeder
{
    /** The core reports every paid plan and the trial include. */
    private const CORE_REPORTS = [
        Feature::ReportQueueOverview,
        Feature::ReportDailyQueue,
        Feature::ReportVisitDetails,
        Feature::ReportDepartmentPerformance,
        Feature::ReportWaitingTime,
    ];

    /** The Growth/Enterprise report set on top of the core reports. */
    private const GROWTH_REPORTS = [
        Feature::ReportServiceDuration,
        Feature::ReportQueueTrends,
        Feature::ReportPeakHours,
        Feature::ReportCancellationNoShow,
        Feature::ReportExport,
    ];

    /** Enterprise-only reports. */
    private const ENTERPRISE_REPORTS = [
        Feature::ReportStaffPerformance,
        Feature::ReportFacilityComparison,
    ];

    public function run(): void
    {
        $this->plan(
            code: 'TRIAL',
            name: 'Trial',
            description: 'Time-limited trial of the core queue features.',
            prices: [],
            toggles: [Feature::QueueManagement, ...self::CORE_REPORTS],
            limits: [[Feature::MaxFacilities, 1], [Feature::MaxStaff, 5], [Feature::MaxKioskDevices, 1], [Feature::MaxDisplayDevices, 1]],
        );

        $this->plan(
            code: 'ESSENTIAL',
            name: 'Essential',
            description: 'Core queue management and reporting for a single facility.',
            prices: [
                ['MONTHLY', 150_000_00],
                ['YEARLY', 1_500_000_00],
            ],
            toggles: [Feature::QueueManagement, ...self::CORE_REPORTS],
            limits: [[Feature::MaxFacilities, 1], [Feature::MaxStaff, 10], [Feature::MaxKioskDevices, 2], [Feature::MaxDisplayDevices, 2]],
        );

        $this->plan(
            code: 'GROWTH',
            name: 'Growth',
            description: 'Multiple facilities, more staff, and the full report set.',
            prices: [
                ['MONTHLY', 400_000_00],
                ['YEARLY', 4_000_000_00],
            ],
            toggles: [
                Feature::QueueManagement,
                ...self::CORE_REPORTS,
                ...self::GROWTH_REPORTS,
            ],
            limits: [[Feature::MaxFacilities, 5], [Feature::MaxStaff, 50], [Feature::MaxKioskDevices, 10], [Feature::MaxDisplayDevices, 10]],
        );

        // Enterprise: limits are a starting recommendation pending capacity and
        // cost validation; no public price is seeded until that is agreed.
        $this->plan(
            code: 'ENTERPRISE',
            name: 'Enterprise',
            description: 'High-capacity plan with every report and cross-facility analytics.',
            prices: [],
            toggles: [
                Feature::QueueManagement,
                ...self::CORE_REPORTS,
                ...self::GROWTH_REPORTS,
                ...self::ENTERPRISE_REPORTS,
            ],
            limits: [[Feature::MaxFacilities, 20], [Feature::MaxStaff, 200], [Feature::MaxKioskDevices, 30], [Feature::MaxDisplayDevices, 30]],
        );

        $tenant = Tenant::query()->where('slug', 'kiyu-demo')->first();
        $trial = Plan::query()->where('code', 'TRIAL')->first();

        if ($tenant !== null && $trial !== null && $tenant->subscriptions()->whereIn('status', ['TRIALING', 'ACTIVE'])->doesntExist()) {
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