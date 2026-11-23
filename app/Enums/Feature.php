<?php

namespace App\Enums;

/**
 * The catalogue of features the platform can sell.
 *
 * This enum is the code-facing list: a feature only exists because code
 * enforces it. Plans grant features through `plan_entitlements` rows keyed by
 * these values, so adding a feature needs a code change here, while granting
 * it to a plan is plain data (no migration).
 *
 * A feature is either a toggle (on/off) or a limit (a numeric cap).
 */
enum Feature: string
{
    case QueueManagement = 'queue_management';
    case BasicReports = 'basic_reports';
    case AdvancedReports = 'advanced_reports';
    case WhatsAppNotifications = 'whatsapp_notifications';

    case MaxFacilities = 'max_facilities';
    case MaxStaff = 'max_staff';
    case MaxKioskDevices = 'max_kiosk_devices';
    case MaxDisplayDevices = 'max_display_devices';

    /**
     * Whether the feature is a numeric limit rather than an on/off toggle.
     */
    public function isLimit(): bool
    {
        return match ($this) {
            self::MaxFacilities, self::MaxStaff, self::MaxKioskDevices, self::MaxDisplayDevices => true,
            default => false,
        };
    }

    /**
     * Whether the feature is an on/off toggle.
     */
    public function isToggle(): bool
    {
        return ! $this->isLimit();
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
