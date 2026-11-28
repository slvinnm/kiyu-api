<?php

namespace App\Enums;

/**
 * The catalogue of features the platform can sell.
 *
 * This enum is the code-facing allowlist: a feature only exists because code
 * enforces it. Plans grant features through `plan_entitlements` rows keyed by
 * these values, and active subscriptions snapshot them into
 * `subscription_entitlements`, so adding a feature needs a code change here
 * while granting it to a plan is plain data.
 *
 * Every feature is a toggle (on/off), an additive capacity, or a period quota.
 * The legacy aggregate codes `basic_reports` and `advanced_reports` are kept
 * only until report routes migrate to the per-report codes; they are never a
 * permanent fallback.
 */
enum Feature: string
{
    case QueueManagement = 'queue_management';

    // Individual report entitlements.
    case ReportQueueOverview = 'report_queue_overview';
    case ReportDailyQueue = 'report_daily_queue';
    case ReportVisitDetails = 'report_visit_details';
    case ReportDepartmentPerformance = 'report_department_performance';
    case ReportWaitingTime = 'report_waiting_time';
    case ReportServiceDuration = 'report_service_duration';
    case ReportQueueTrends = 'report_queue_trends';
    case ReportPeakHours = 'report_peak_hours';
    case ReportCancellationNoShow = 'report_cancellation_no_show';
    case ReportStaffPerformance = 'report_staff_performance';
    case ReportFacilityComparison = 'report_facility_comparison';
    case ReportExport = 'report_export';

    case WhatsAppNotifications = 'whatsapp_notifications';
    case WhatsAppMessagesPerPeriod = 'whatsapp_messages_per_period';

    // Capacity (additive) limits.
    case MaxFacilities = 'max_facilities';
    case MaxStaff = 'max_staff';
    case MaxKioskDevices = 'max_kiosk_devices';
    case MaxDisplayDevices = 'max_display_devices';

    // Legacy aggregate report toggles (retired once report routes migrate).
    case BasicReports = 'basic_reports';
    case AdvancedReports = 'advanced_reports';

    /**
     * The kind of value this feature carries.
     */
    public function valueType(): FeatureValueType
    {
        return match ($this) {
            self::MaxFacilities, self::MaxStaff, self::MaxKioskDevices, self::MaxDisplayDevices => FeatureValueType::Capacity,
            self::WhatsAppMessagesPerPeriod => FeatureValueType::Quota,
            default => FeatureValueType::Boolean,
        };
    }

    /**
     * The administrative category, for the registry and portal grouping.
     */
    public function category(): FeatureCategory
    {
        return match ($this) {
            self::QueueManagement => FeatureCategory::Core,
            self::ReportExport => FeatureCategory::Export,
            self::WhatsAppNotifications, self::WhatsAppMessagesPerPeriod => FeatureCategory::Notification,
            self::MaxFacilities, self::MaxStaff, self::MaxKioskDevices, self::MaxDisplayDevices => FeatureCategory::Capacity,
            default => FeatureCategory::Report,
        };
    }

    /**
     * Features that must be granted before this one is usable.
     *
     * Reports need the queue data they describe; the WhatsApp quota needs the
     * notification toggle. Entitlement never bypasses these dependencies.
     *
     * @return list<self>
     */
    public function dependencies(): array
    {
        return match ($this) {
            self::ReportQueueOverview,
            self::ReportDailyQueue,
            self::ReportVisitDetails,
            self::ReportDepartmentPerformance,
            self::ReportWaitingTime,
            self::ReportServiceDuration,
            self::ReportQueueTrends,
            self::ReportPeakHours,
            self::ReportCancellationNoShow,
            self::ReportStaffPerformance,
            self::ReportFacilityComparison => [self::QueueManagement],
            self::WhatsAppMessagesPerPeriod => [self::WhatsAppNotifications],
            default => [],
        };
    }

    /**
     * Whether the feature is a numeric capacity limit.
     */
    public function isLimit(): bool
    {
        return $this->valueType() === FeatureValueType::Capacity;
    }

    /**
     * Whether the feature is a period quota.
     */
    public function isQuota(): bool
    {
        return $this->valueType() === FeatureValueType::Quota;
    }

    /**
     * Whether the feature is an on/off toggle.
     */
    public function isToggle(): bool
    {
        return $this->valueType() === FeatureValueType::Boolean;
    }

    /**
     * The limit applied to a plan that does not grant this feature.
     */
    public function defaultLimit(): int
    {
        return 0;
    }

    /**
     * Every feature code, for validation rules.
     *
     * @return list<string>
     */
    public static function allValues(): array
    {
        return array_map(fn(self $feature): string => $feature->value, self::cases());
    }
}
