<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case Submitted = 'submitted';
    case InProgress = 'in_progress';
    case SentBack = 'sent_back';
    case Resubmitted = 'resubmitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case CertificateReady = 'certificate_ready';
    case Completed = 'completed';
    case ExpiredUnpaid = 'expired_unpaid';
}

