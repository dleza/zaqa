<?php

namespace App\Domain\Verification;

use App\Enums\VerificationState;
use App\Models\Qualification;
use App\Models\QualificationCertificate;

final class VerificationLookupStatusMapper
{
    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_RETURNED_FOR_CORRECTION = 'returned_for_correction';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CERTIFICATE_ISSUED = 'certificate_issued';

    public const STATUS_CERTIFICATE_REVOKED = 'certificate_revoked';

    public const STATUS_NOT_FOUND = 'not_found';

    /**
     * @return array{status: string, status_label: string, message: string, tone: string}
     */
    public function resolveForQualification(Qualification $qualification, ?QualificationCertificate $certificate): array
    {
        $certificateContext = $this->resolveCertificateContext($qualification, $certificate);

        if ($certificateContext['status'] === self::STATUS_CERTIFICATE_REVOKED) {
            return [
                'status' => self::STATUS_CERTIFICATE_REVOKED,
                'status_label' => $certificateContext['is_rejection']
                    ? 'Rejection Notice Recalled'
                    : 'Certificate Recalled',
                'message' => 'The reference is recognized, but the certificate is no longer valid.',
                'tone' => 'warning',
            ];
        }

        if ($certificateContext['status'] === self::STATUS_CERTIFICATE_ISSUED) {
            return [
                'status' => self::STATUS_CERTIFICATE_ISSUED,
                'status_label' => $certificateContext['is_rejection']
                    ? 'Rejection Notice Issued'
                    : 'Certificate Issued',
                'message' => $certificateContext['is_rejection']
                    ? 'ZAQA issued a rejection notice for this qualification.'
                    : 'A ZAQA verification certificate has been issued for this qualification.',
                'tone' => 'success',
            ];
        }

        $state = $qualification->verification_state;

        return match ($state) {
            VerificationState::ReturnedToApplicant => [
                'status' => self::STATUS_RETURNED_FOR_CORRECTION,
                'status_label' => 'Returned for Correction',
                'message' => 'This verification was returned to the applicant for correction and is not yet complete.',
                'tone' => 'warning',
            ],
            VerificationState::Rejected => [
                'status' => self::STATUS_REJECTED,
                'status_label' => 'Rejected',
                'message' => 'ZAQA has completed review and did not verify this qualification.',
                'tone' => 'danger',
            ],
            VerificationState::ApprovedForCertificate => [
                'status' => self::STATUS_APPROVED,
                'status_label' => 'Approved',
                'message' => 'This qualification has been approved and is awaiting certificate issuance.',
                'tone' => 'success',
            ],
            VerificationState::CertificateIssued => [
                'status' => self::STATUS_CERTIFICATE_ISSUED,
                'status_label' => 'Certificate Issued',
                'message' => 'A ZAQA verification certificate has been issued for this qualification.',
                'tone' => 'success',
            ],
            VerificationState::Closed => [
                'status' => self::STATUS_COMPLETED,
                'status_label' => 'Completed',
                'message' => 'This verification process has been completed.',
                'tone' => 'neutral',
            ],
            default => [
                'status' => self::STATUS_IN_REVIEW,
                'status_label' => 'In Review',
                'message' => 'This verification is still being processed.',
                'tone' => 'neutral',
            ],
        };
    }

    /**
     * @return array{
     *   status: string,
     *   is_rejection: bool,
     *   certificate: QualificationCertificate|null,
     *   revoked: bool,
     *   revoked_at: string|null
     * }
     */
    public function resolveCertificateContext(Qualification $qualification, ?QualificationCertificate $certificate = null): array
    {
        $certificate ??= $this->resolveDisplayCertificate($qualification);

        if (! $certificate instanceof QualificationCertificate) {
            return [
                'status' => 'none',
                'is_rejection' => false,
                'certificate' => null,
                'revoked' => false,
                'revoked_at' => null,
            ];
        }

        $isRejection = $certificate->isRejectionCertificate();

        if ($certificate->status === QualificationCertificate::STATUS_REVOKED) {
            $hasReplacement = QualificationCertificate::query()
                ->where('qualification_id', $qualification->id)
                ->where('status', QualificationCertificate::STATUS_ISSUED)
                ->where('id', '>', $certificate->id)
                ->exists();

            if ($hasReplacement) {
                $replacement = QualificationCertificate::query()
                    ->where('qualification_id', $qualification->id)
                    ->where('status', QualificationCertificate::STATUS_ISSUED)
                    ->latest('id')
                    ->first();

                return [
                    'status' => self::STATUS_CERTIFICATE_ISSUED,
                    'is_rejection' => $replacement?->isRejectionCertificate() ?? false,
                    'certificate' => $replacement,
                    'revoked' => false,
                    'revoked_at' => null,
                ];
            }

            return [
                'status' => self::STATUS_CERTIFICATE_REVOKED,
                'is_rejection' => $isRejection,
                'certificate' => $certificate,
                'revoked' => true,
                'revoked_at' => optional($certificate->revoked_at)?->toDateString(),
            ];
        }

        if ($certificate->status === QualificationCertificate::STATUS_ISSUED) {
            return [
                'status' => self::STATUS_CERTIFICATE_ISSUED,
                'is_rejection' => $isRejection,
                'certificate' => $certificate,
                'revoked' => false,
                'revoked_at' => null,
            ];
        }

        return [
            'status' => 'none',
            'is_rejection' => $isRejection,
            'certificate' => null,
            'revoked' => false,
            'revoked_at' => null,
        ];
    }

    public function resolveDisplayCertificate(Qualification $qualification): ?QualificationCertificate
    {
        $qualification->loadMissing('certificates');

        $issued = $qualification->certificates
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->sortByDesc('id')
            ->first();

        if ($issued instanceof QualificationCertificate) {
            return $issued;
        }

        return $qualification->certificates
            ->sortByDesc('id')
            ->first();
    }

    public function certificateTypeLabel(?QualificationCertificate $certificate): ?string
    {
        if (! $certificate instanceof QualificationCertificate) {
            return null;
        }

        return $certificate->isRejectionCertificate()
            ? 'Rejection Notice'
            : 'Verification Certificate';
    }

    public function certificateTypeKey(?QualificationCertificate $certificate): ?string
    {
        if (! $certificate instanceof QualificationCertificate) {
            return null;
        }

        return $certificate->isRejectionCertificate() ? 'rejection' : 'verification';
    }
}
