<?php

namespace Database\Seeders;

use App\Enums\Feature;
use App\Models\Addon;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Add-on catalogue.
 *
 * Prices are seeded with `amount = 0, is_active = false` — explicit
 * placeholders, because no commercial price has been decided. The quota values
 * for the WhatsApp pack are likewise placeholders (`quota` in the entitlement
 * value) and must be replaced with a real, cost-based number before sale.
 *
 * Eligibility follows the product matrix: paid plans can buy add-ons; the Trial
 * cannot; Growth already includes the export and report add-ons, so those are
 * only offered where the plan does not already grant them.
 */
class AddonSeeder extends Seeder
{
    public function run(): void
    {
        $reports = [
            'ADDON_REPORT_SERVICE_DURATION' => Feature::ReportServiceDuration,
            'ADDON_REPORT_QUEUE_TRENDS' => Feature::ReportQueueTrends,
            'ADDON_REPORT_PEAK_HOURS' => Feature::ReportPeakHours,
            'ADDON_REPORT_CANCELLATION_NO_SHOW' => Feature::ReportCancellationNoShow,
            'ADDON_REPORT_STAFF_PERFORMANCE' => Feature::ReportStaffPerformance,
            'ADDON_REPORT_FACILITY_COMPARISON' => Feature::ReportFacilityComparison,
        ];

        foreach ($reports as $code => $feature) {
            $this->reportAddon($code, $feature);
        }

        $this->addon('ADDON_REPORT_EXPORT', 'Report Export', 'export', [
            [Feature::ReportExport, null],
        ]);

        $this->addon('ADDON_WHATSAPP_1000', 'WhatsApp 1000 messages', 'notification', [
            [Feature::WhatsAppNotifications, null],
            [Feature::WhatsAppMessagesPerPeriod, ['limit' => 1000]],
        ]);

        $this->capacityAddon('ADDON_FACILITY_1', 'Additional facility', Feature::MaxFacilities, 1);
        $this->capacityAddon('ADDON_STAFF_10', 'Staff pack (10)', Feature::MaxStaff, 10);
        $this->capacityAddon('ADDON_KIOSK_1', 'Additional kiosk', Feature::MaxKioskDevices, 1);
        $this->capacityAddon('ADDON_DISPLAY_1', 'Additional display', Feature::MaxDisplayDevices, 1);

        $this->seedEligibility();
        $this->seedPlaceholderPrices();
    }

    private function reportAddon(string $code, Feature $feature): void
    {
        $this->addon($code, ucwords(str_replace('_', ' ', strtolower(substr($code, 13)))), 'report', [
            [$feature, null],
        ]);
    }

    private function capacityAddon(string $code, string $name, Feature $feature, int $amount): void
    {
        $this->addon($code, $name, 'capacity', [
            [$feature, ['limit' => $amount]],
        ]);
    }

    /**
     * @param  list<array{0: Feature, 1: array<string, mixed>|null}>  $entitlements
     */
    private function addon(string $code, string $name, string $category, array $entitlements): void
    {
        $addon = Addon::query()->updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'category' => $category, 'is_active' => true],
        );

        foreach ($entitlements as [$feature, $value]) {
            $addon->entitlements()->updateOrCreate(
                ['feature_code' => $feature->value],
                ['value' => $value],
            );
        }
    }

    /**
     * Offer each add-on to the plans that do not already include it.
     */
    private function seedEligibility(): void
    {
        $essential = Plan::query()->where('code', 'ESSENTIAL')->first();
        $growth = Plan::query()->where('code', 'GROWTH')->first();
        $enterprise = Plan::query()->where('code', 'ENTERPRISE')->first();

        $addons = Addon::query()->get()->keyBy('code');

        $matrix = [
            'ESSENTIAL' => ['ADDON_REPORT_SERVICE_DURATION', 'ADDON_REPORT_QUEUE_TRENDS', 'ADDON_REPORT_PEAK_HOURS', 'ADDON_REPORT_CANCELLATION_NO_SHOW', 'ADDON_REPORT_STAFF_PERFORMANCE', 'ADDON_REPORT_FACILITY_COMPARISON', 'ADDON_REPORT_EXPORT', 'ADDON_WHATSAPP_1000', 'ADDON_FACILITY_1', 'ADDON_STAFF_10', 'ADDON_KIOSK_1', 'ADDON_DISPLAY_1'],
            'GROWTH' => ['ADDON_REPORT_STAFF_PERFORMANCE', 'ADDON_REPORT_FACILITY_COMPARISON', 'ADDON_WHATSAPP_1000', 'ADDON_FACILITY_1', 'ADDON_STAFF_10', 'ADDON_KIOSK_1', 'ADDON_DISPLAY_1'],
            'ENTERPRISE' => ['ADDON_WHATSAPP_1000', 'ADDON_FACILITY_1', 'ADDON_STAFF_10', 'ADDON_KIOSK_1', 'ADDON_DISPLAY_1'],
        ];

        foreach (['ESSENTIAL' => $essential, 'GROWTH' => $growth, 'ENTERPRISE' => $enterprise] as $planCode => $plan) {
            if ($plan === null) {
                continue;
            }

            foreach ($matrix[$planCode] as $addonCode) {
                $addon = $addons[$addonCode] ?? null;

                if ($addon !== null) {
                    $plan->addonEligibilities()->updateOrCreate(['addon_id' => $addon->id]);
                }
            }
        }
    }

    /**
     * Seed placeholder, inactive prices so the catalogue rows exist without a
     * commercial number. A real price activates the row.
     */
    private function seedPlaceholderPrices(): void
    {
        Addon::query()->each(function (Addon $addon): void {
            foreach (['MONTHLY', 'YEARLY'] as $interval) {
                $addon->prices()->updateOrCreate(
                    ['billing_interval' => $interval, 'currency' => 'IDR', 'provider' => ''],
                    ['amount' => 0, 'is_active' => false],
                );
            }
        });
    }
}
