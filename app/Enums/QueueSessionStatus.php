<?php

namespace App\Enums;

enum VisitStatus: string
{
    case AwaitingCheckin = 'AWAITING_CHECKIN';
    case Waiting = 'WAITING';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    /**
     * Whether the visit is finished and must not advance any further.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
