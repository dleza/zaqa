<?php

namespace App\Enums;

enum LearnerRecordSubmissionBatchStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case PendingReview = 'pending_review';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }
}
