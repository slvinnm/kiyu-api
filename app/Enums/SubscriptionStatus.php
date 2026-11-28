<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'TRIALING';
    case Active = 'ACTIVE';
    case PastDue = 'PAST_DUE';
    case PendingPayment = 'PENDING_PAYMENT';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';

    /**
     * Whether the subscription grants access right now.
     */
    public function isEffective(): bool
    {
        return in_array($this, self::grantingStatuses(), true);
    }

    /**
     * Statuses that actually grant features and capacity (TRIALING/ACTIVE).
     *
     * @return list<self>
     */
    public static function grantingStatuses(): array
    {
        return [self::Trialing, self::Active];
    }

    /**
     * @return list<string>
     */
    public static function grantingValues(): array
    {
        return array_map(fn(self $status): string => $status->value, self::grantingStatuses());
    }

    /**
     * Statuses that occupy the tenant's single subscription slot.
     *
     * This is deliberately wider than granting: PENDING_PAYMENT and PAST_DUE
     * reserve the slot and are what a charge is raised against, but they grant
     * no features or capacity until a verified payment promotes them to ACTIVE.
     *
     * @return list<self>
     */
    public static function effectiveStatuses(): array
    {
        return [self::Trialing, self::Active, self::PastDue, self::PendingPayment];
    }

    /**
     * @return list<string>
     */
    public static function effectiveValues(): array
    {
        return array_map(fn(self $status): string => $status->value, self::effectiveStatuses());
    }
}
