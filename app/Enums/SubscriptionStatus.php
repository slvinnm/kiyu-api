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
     * Statuses that count toward the "one effective subscription per tenant"
     * rule.
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
