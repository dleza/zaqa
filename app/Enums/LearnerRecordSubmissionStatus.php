<?php

namespace App\Enums;

enum LearnerRecordSubmissionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
    case Superseded = 'superseded';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Duplicate, self::Superseded], true);
    }
}
