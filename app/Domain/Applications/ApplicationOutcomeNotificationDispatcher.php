<?php

namespace App\Domain\Applications;

use App\Domain\Notifications\OutboundMailService;
use App\Mail\ApplicationOutcomeNotificationMail;
use App\Mail\QualificationCertificateIssuedMail;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use Illuminate\Mail\Mailable;

class ApplicationOutcomeNotificationDispatcher
{
    public function __construct(
        private readonly OutboundMailService $outboundMail,
    ) {}

    public function notifyApplicationApproved(Application $application): void
    {
        $this->notifyOutcome($application, 'application_approved', 'ZAQA application approved');
    }

    public function notifyApplicationRejected(Application $application, string $reason): void
    {
        $this->notifyOutcome($application, 'application_rejected', 'ZAQA application decision', [
            'reason' => $reason,
        ]);
    }

    public function notifyQualificationRejected(Application $application, Qualification $qualification, string $reason): void
    {
        $this->notifyOutcome($application, 'qualification_rejected', 'ZAQA qualification decision', [
            'reason' => $reason,
            'qualification_title' => (string) ($qualification->title_of_qualification ?? ''),
        ]);
    }

    public function notifyCertificateIssuedCopy(
        Qualification $qualification,
        Application $application,
        QualificationCertificate $certificate,
    ): void {
        $additionalEmail = ApplicationNotificationContact::additionalEmailForOutcome($application);
        if ($additionalEmail === null) {
            return;
        }

        $this->queueMailable(
            mailable: new QualificationCertificateIssuedMail(
                qualification: $qualification,
                application: $application,
                certificate: $certificate,
            ),
            to: $additionalEmail,
            application: $application,
            templateKey: 'qualification_certificate_issued_additional',
            subject: 'ZAQA qualification certificate issued',
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function notifyOutcome(Application $application, string $outcome, string $subject, array $context = []): void
    {
        $application->loadMissing('applicant');
        $applicantEmail = trim((string) ($application->applicant?->email ?? ''));
        $additionalEmail = ApplicationNotificationContact::additionalEmailForOutcome($application);

        if ($applicantEmail !== '') {
            $this->queueMailable(
                mailable: new ApplicationOutcomeNotificationMail($application, $outcome, $context),
                to: $applicantEmail,
                application: $application,
                templateKey: 'application_outcome_'.$outcome,
                subject: $subject,
            );
        }

        if ($additionalEmail !== null) {
            $this->queueMailable(
                mailable: new ApplicationOutcomeNotificationMail($application, $outcome, $context),
                to: $additionalEmail,
                application: $application,
                templateKey: 'application_outcome_'.$outcome.'_additional',
                subject: $subject,
            );
        }
    }

    private function queueMailable(
        Mailable $mailable,
        string $to,
        Application $application,
        string $templateKey,
        string $subject,
    ): void {
        $this->outboundMail->queue(
            mailable: $mailable,
            to: $to,
            logContext: [
                'user_id' => $application->applicant_user_id,
                'application_id' => $application->id,
                'email' => $to,
                'subject' => $subject,
                'template_key' => $templateKey,
            ],
        );
    }
}
