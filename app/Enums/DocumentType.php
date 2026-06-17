<?php

namespace App\Enums;

enum DocumentType: string
{
    case NrcCopy = 'nrc_copy';
    case PassportCopy = 'passport_copy';
    case CertificateCopy = 'certificate_copy';
    case Transcript = 'transcript';
    case ConsentFormSigned = 'consent_form_signed';
    case ZaqaConsentFormSigned = 'zaqa_consent_form_signed';
    case PaymentProof = 'payment_proof';
    case GeneratedReceipt = 'generated_receipt';
    case GeneratedCertificate = 'generated_certificate';
    case OtherSupportingDocument = 'other_supporting_document';
    /** Internal: Level 1 supporting attachment at completion (admin upload). */
    case Level1ReviewAttachment = 'level1_review_attachment';
    /** Internal: Level 1 evaluation report at completion (admin upload). */
    case Level1EvaluationReport = 'level1_evaluation_report';
    /** Internal: optional attachment when Level 2 sends back to Level 1 for correction. */
    case Level2SendBackToLevel1Attachment = 'level2_send_back_to_level1_attachment';
}
