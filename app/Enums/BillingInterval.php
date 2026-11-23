<?php

namespace App\Enums;

enum BillingInterval: string
{
    case Monthly = 'MONTHLY';
    case Yearly = 'YEARLY';

    /**
     * The number of months one interval covers.
     */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Yearly => 12,
        };
    }
}
