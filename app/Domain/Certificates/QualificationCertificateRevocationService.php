<?php

namespace App\Domain\Certificates;

use App\Domain\Audit\AuditLogService;
use App\Models\QualificationCertificate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationCertificateRevocationService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly CertificateRevocationNotificationDispatcher $notifications,
    ) {}

    public function revoke(
        QualificationCertificate $certificate,
        User $actor,
        string $reason,
        ?string $publicNote = null,
    ): QualificationCertificate {
        if (! $actor->can('certificates.revoke')) {
            throw ValidationException::withMessages([
                'authorization' => 'You do not have permission to revoke certificates.',
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'revocation_reason' => 'Provide a reason for revoking this certificate.',
            ]);
        }

        $publicNote = $publicNote !== null ? trim($publicNote) : null;
        if ($publicNote === '') {
            $publicNote = null;
        }

        return DB::transaction(function () use ($certificate, $actor, $reason, $publicNote) {
            $certificate->refresh();

            if ($certificate->status !== QualificationCertificate::STATUS_ISSUED) {
                throw ValidationException::withMessages([
                    'certificate' => 'Only active certificates can be revoked.',
                ]);
            }

            $before = $certificate->only([
                'status',
                'revoked_at',
                'revoked_by_user_id',
                'revocation_reason',
                'revocation_public_note',
            ]);

            $certificate->forceFill([
                'status' => QualificationCertificate::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->id,
                'revocation_reason' => $reason,
                'revocation_public_note' => $publicNote,
            ])->save();

            $after = $certificate->only([
                'status',
                'revoked_at',
                'revoked_by_user_id',
                'revocation_reason',
                'revocation_public_note',
            ]);

            $this->audit->record(
                eventType: $certificate->isRejectionCertificate()
                    ? 'certificates.rejection_revoked'
                    : 'certificates.qualification_revoked',
                module: 'Certificates',
                actionName: $certificate->isRejectionCertificate()
                    ? 'rejection_certificate_revoked'
                    : 'qualification_certificate_revoked',
                message: $certificate->isRejectionCertificate()
                    ? 'Rejection notice revoked.'
                    : 'Qualification certificate (CVEQ) revoked.',
                entityType: QualificationCertificate::class,
                entityId: $certificate->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'certificate_number' => $certificate->certificate_number,
                    'certificate_type' => $certificate->certificate_type ?: QualificationCertificate::TYPE_VERIFICATION,
                    'qualification_id' => $certificate->qualification_id,
                    'application_id' => $certificate->application_id,
                    'revoked_at' => optional($certificate->revoked_at)?->toIso8601String(),
                    'has_public_note' => $publicNote !== null,
                ],
                actor: $actor,
            );

            $this->notifications->notify($certificate, $actor);

            return $certificate;
        });
    }
}
