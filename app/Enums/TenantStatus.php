<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Pending = 'PENDING';
    case Cancelled = 'CANCELLED';

    /**
     * Determine whether the tenant may perform operational work.
     */
    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
