<?php

namespace App\Enums;

enum LearnerRecordReviewDecision: string
{
    case ApproveNew = 'approve_new';
    case ApproveUpdate = 'approve_update';
    case RejectDuplicate = 'reject_duplicate';
}
