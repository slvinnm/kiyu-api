<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'TRIALING';
    case Active = 'ACTIVE';
    case PastDue = 'PAST_DUE';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';

    /**
     * Whether the subscription grants access right now.
     */
    public function isEffective(): bool
    {
        return in_array($this, [self::Trialing, self::Active], true);
    }

    /**
     * Statuses that occupy the tenant's single subscription slot.
     *
     * This is deliberately wider than {@see isEffective()}: PAST_DUE means the
     * tenant has a subscription on file that it has not paid for. It reserves
     * the slot and is what a charge is raised against, but it grants no
     * features and no capacity until a payment promotes it to ACTIVE.
     *
     * @return list<self>
     */
    public static function effectiveStatuses(): array
    {
        return [self::Trialing, self::Active, self::PastDue];
    }

    /**
     * @return list<string>
     */
    public static function effectiveValues(): array
    {
        return array_map(fn(self $status): string => $status->value, self::effectiveStatuses());
    }
}
