<?php

namespace App\Enums;

enum DocumentType: string
{
    case NrcCopy = 'nrc_copy';
    case PassportCopy = 'passport_copy';
    case CertificateCopy = 'certificate_copy';
    case Transcript = 'transcript';
    case ConsentFormSigned = 'consent_form_signed';
    case PaymentProof = 'payment_proof';
    case GeneratedReceipt = 'generated_receipt';
    case GeneratedCertificate = 'generated_certificate';
    case OtherSupportingDocument = 'other_supporting_document';
}

