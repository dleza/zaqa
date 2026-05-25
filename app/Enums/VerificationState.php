<?php

namespace App\Enums;

enum VerificationState: string
{
    case AwaitingAutoVerification = 'awaiting_auto_verification';
    case AwaitingAssignment = 'awaiting_assignment';
    case AssignedToLevel1 = 'assigned_to_level1';
    case UnderLevel1Review = 'under_level1_review';
    case UnderLevel2Review = 'under_level2_review';
    case ReturnedToApplicant = 'returned_to_applicant';
    case AutoVerifiedPendingLevel2 = 'auto_verified_pending_level2';
    case ApprovedForCertificate = 'approved_for_certificate';
    case Rejected = 'rejected';
    case CertificateIssued = 'certificate_issued';
    case Closed = 'closed';
}
