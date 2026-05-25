<?php

namespace App\Enums;

enum PaymentAttemptStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Unknown = 'unknown';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Confirmed, self::Failed, self::Rejected, self::Expired, self::Unknown], true);
    }
}

