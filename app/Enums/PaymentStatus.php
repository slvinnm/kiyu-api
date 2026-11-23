<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'PENDING';
    case Paid = 'PAID';
    case Failed = 'FAILED';
    case Refunded = 'REFUNDED';

    /**
     * Map a Midtrans transaction status onto our status.
     */
    public static function fromMidtrans(string $status): self
    {
        return match (strtolower($status)) {
            'settlement', 'capture' => self::Paid,
            'pending' => self::Pending,
            'deny', 'cancel', 'expire', 'failure' => self::Failed,
            'refund', 'partial_refund' => self::Refunded,
            default => self::Pending,
        };
    }
}
