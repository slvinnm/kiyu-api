<?php

namespace App\Enums;

enum ServiceTransferStatus: string
{
    case Pending = 'PENDING';
    case Accepted = 'ACCEPTED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
