<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Draft = 'draft';
    case Initiated = 'initiated';
    case PendingConfirmation = 'pending_confirmation';
    case AwaitingFinanceReview = 'awaiting_finance_review';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Expired = 'expired';
}

