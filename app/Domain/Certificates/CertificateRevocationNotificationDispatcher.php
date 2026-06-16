<?php

namespace App\Domain\Certificates;

use App\Domain\Applications\ApplicationNotificationContact;
use App\Domain\Audit\AuditLogService;
use App\Domain\Notifications\OutboundMailService;
use App\Mail\CertificateRevokedMail;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\User;
use App\Notifications\Certificates\CertificateRevokedApplicantPortalNotification;

class CertificateRevocationNotificationDispatcher
{
    public function __construct(
        private readonly OutboundMailService $outboundMail,
        private readonly AuditLogService $audit,
    ) {}

    public function notify(QualificationCertificate $certificate, User $actor): void
    {
        $certificate->loadMissing(['qualification', 'application.applicant']);
        $application = $certificate->application;
        $qualification = $certificate->qualification;

        if (! $application instanceof Application || ! $qualification instanceof Qualification) {
            return;
        }

        $applicant = $application->applicant;
        if (! $applicant instanceof User) {
            return;
        }

        $qualificationTitle = trim((string) ($qualification->title_of_qualification ?? ''));
        $publicNote = $certificate->revocation_public_note;
        $isRejection = $certificate->isRejectionCertificate();
        $linkUrl = '/applicant/applications/'.$application->id;

        $applicant->notify(new CertificateRevokedApplicantPortalNotification(
            applicationId: $application->id,
            applicationReference: (string) $application->application_number,
            qualificationId: $qualification->id,
            qualificationTitle: $qualificationTitle,
            certificateType: $certificate->certificate_type ?: QualificationCertificate::TYPE_VERIFICATION,
            publicNote: $publicNote,
            linkUrl: $linkUrl,
        ));

        $email = trim((string) ($applicant->email ?? ''));
        if ($email !== '') {
            $this->outboundMail->queue(
                mailable: new CertificateRevokedMail(
                    application: $application,
                    qualification: $qualification,
                    certificate: $certificate,
                ),
                to: $email,
                logContext: [
                    'user_id' => $application->applicant_user_id,
                    'application_id' => $application->id,
                    'email' => $email,
                    'subject' => $isRejection
                        ? 'ZAQA rejection notice recalled'
                        : 'ZAQA verification certificate recalled',
                    'template_key' => $isRejection
                        ? 'rejection_certificate_revoked'
                        : 'verification_certificate_revoked',
                ],
            );
        }

        $additionalEmail = ApplicationNotificationContact::additionalEmailForOutcome($application);
        if ($additionalEmail !== null) {
            $this->outboundMail->queue(
                mailable: new CertificateRevokedMail(
                    application: $application,
                    qualification: $qualification,
                    certificate: $certificate,
                ),
                to: $additionalEmail,
                logContext: [
                    'user_id' => $application->applicant_user_id,
                    'application_id' => $application->id,
                    'email' => $additionalEmail,
                    'subject' => $isRejection
                        ? 'ZAQA rejection notice recalled'
                        : 'ZAQA verification certificate recalled',
                    'template_key' => $isRejection
                        ? 'rejection_certificate_revoked_additional'
                        : 'verification_certificate_revoked_additional',
                ],
            );
        }

        $this->audit->record(
            eventType: 'notifications.certificate_revoked',
            module: 'Notifications',
            actionName: 'certificate_revoked_applicant_notified',
            message: 'Applicant notified of certificate revocation.',
            entityType: QualificationCertificate::class,
            entityId: $certificate->id,
            beforeState: null,
            afterState: null,
            metadata: [
                'application_id' => $application->id,
                'qualification_id' => $qualification->id,
                'certificate_type' => $certificate->certificate_type,
                'applicant_user_id' => $application->applicant_user_id,
                'has_public_note' => $publicNote !== null && trim((string) $publicNote) !== '',
            ],
            actor: $actor,
        );
    }
}
