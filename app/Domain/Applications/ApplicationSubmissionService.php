<?php

namespace App\Domain\Applications;

use App\Domain\Applications\Events\ApplicationSubmitted;
use App\Domain\Audit\AuditLogService;
use App\Domain\Payments\ApplicationPaymentSatisfaction;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\ApplicationStatus;
use App\Enums\ConsentType;
use App\Enums\DocumentType;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\User;
use App\Support\CountryIso;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ApplicationSubmissionService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
        private readonly QualificationCaptureService $qualificationCapture,
        private readonly ApplicationPaymentSatisfaction $paymentSatisfaction,
    ) {}

    public function submit(Application $application, User $actor): Application
    {
        return DB::transaction(function () use ($application, $actor) {
            $application->refresh();
            $application->load(['qualifications.consentForm', 'documents', 'invoice', 'payments']);

            if (! in_array($application->current_status, [ApplicationStatus::Draft, ApplicationStatus::SentBack], true)) {
                throw ValidationException::withMessages([
                    'application' => 'This application cannot be submitted in its current state.',
                ]);
            }

            if ($application->qualifications->count() < 1) {
                throw ValidationException::withMessages([
                    'qualification' => 'Qualification details are required before submission.',
                ]);
            }

            $missingHolderName = false;
            $missingHolderId = false;
            foreach ($application->qualifications as $q) {
                /** @var Qualification $q */
                $hn = trim((string) ($q->qualification_holder_name ?? ''));
                $hid = trim((string) ($q->nrc_passport_number ?? ''));
                $missingHolderName = $missingHolderName || $hn === '';
                $missingHolderId = $missingHolderId || $hid === '';
            }

            if ($missingHolderName || $missingHolderId) {
                $this->audit->record(
                    eventType: 'applications.submission_blocked_due_to_missing_holder_identity',
                    module: 'Applications',
                    actionName: 'submission_blocked',
                    message: 'Submission blocked: missing qualification holder identity data.',
                    entityType: Application::class,
                    entityId: $application->id,
                    metadata: [
                        'missing_holder_name' => $missingHolderName,
                        'missing_holder_identity' => $missingHolderId,
                    ],
                    actor: $actor,
                );

                throw ValidationException::withMessages([
                    'qualification_holder_name' => 'Qualification holder name is required before submission.',
                    'nrc_passport_number' => 'Qualification holder NRC/Passport number is required before submission.',
                ]);
            }

            $requiredDocumentTypes = $this->requiredDocumentTypes($application);
            $missingDocuments = $this->missingDocumentTypes($application, $requiredDocumentTypes);

            if ($missingDocuments !== []) {
                throw ValidationException::withMessages([
                    'documents' => 'Missing required documents: '.implode(', ', $missingDocuments).'.',
                ]);
            }

            $this->assertConsentSatisfied($application);

            $this->assertWizardDeclarationsComplete($application);

            if (! $this->paymentSatisfaction->isSatisfied($application)) {
                $latestPayment = $application->payments->sortByDesc('id')->first();
                $this->audit->record(
                    eventType: 'applications.submission_blocked_due_to_payment',
                    module: 'Applications',
                    actionName: 'submission_blocked',
                    message: 'Submission blocked: cumulative confirmed payments do not cover the current fee total.',
                    entityType: Application::class,
                    entityId: $application->id,
                    metadata: [
                        'invoice_status' => $application->invoice?->status?->value ?? null,
                        'payment_status' => $latestPayment?->status?->value ?? null,
                        'payment_method' => $latestPayment?->method?->value ?? null,
                        'outstanding_cents' => $this->paymentSatisfaction->outstandingCents($application),
                    ],
                    actor: $actor,
                );

                throw ValidationException::withMessages([
                    'payment' => 'Payment must cover the full fee for your qualifications before submission. If you changed qualification type or locality, pay any additional balance shown on the Payment step.',
                ]);
            }

            $fromStatus = $application->current_status;
            $toStatus = $fromStatus === ApplicationStatus::SentBack
                ? ApplicationStatus::Resubmitted
                : ApplicationStatus::Submitted;

            $now = now();

            $before = [
                'current_status' => $fromStatus->value,
                'verification_state' => $application->verification_state?->value ?? null,
                'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
                'service_deadline_at' => optional($application->service_deadline_at)?->toIso8601String(),
                'assigned_level1_user_id' => $application->assigned_level1_user_id,
                'assigned_by_level2_user_id' => $application->assigned_by_level2_user_id,
            ];

            $application->forceFill([
                'current_status' => $toStatus,
                // Once submitted (or resubmitted), applications must enter verification intake.
                'verification_state' => VerificationState::AwaitingAssignment,
                'submitted_at' => $application->submitted_at ?? $now,
                'service_deadline_at' => $application->service_deadline_at ?? $now->copy()->addDays($application->is_foreign ? 60 : 14),
                // Resubmissions must be re-assessed and re-assigned by Level 2.
                'assigned_level1_user_id' => null,
                'assigned_by_level2_user_id' => null,
            ])->save();

            ApplicationStatusHistory::create([
                'application_id' => $application->id,
                'from_status' => $fromStatus->value,
                'to_status' => $toStatus->value,
                'changed_by_user_id' => $actor->id,
                'comment' => $toStatus === ApplicationStatus::Resubmitted ? 'Application resubmitted.' : 'Application submitted.',
                'changed_at' => $now,
                'metadata' => [
                    'required_documents' => array_map(fn (DocumentType $t) => $t->value, $requiredDocumentTypes),
                ],
            ]);

            $after = [
                'current_status' => $application->current_status->value,
                'verification_state' => $application->verification_state?->value ?? null,
                'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
                'service_deadline_at' => optional($application->service_deadline_at)?->toIso8601String(),
                'assigned_level1_user_id' => $application->assigned_level1_user_id,
                'assigned_by_level2_user_id' => $application->assigned_by_level2_user_id,
            ];

            $eventType = $toStatus === ApplicationStatus::Resubmitted
                ? 'applications.resubmitted'
                : 'applications.submitted';

            $actionName = $toStatus === ApplicationStatus::Resubmitted
                ? 'resubmitted'
                : 'submitted';

            $this->audit->record(
                eventType: $eventType,
                module: 'Applications',
                actionName: $actionName,
                message: $toStatus === ApplicationStatus::Resubmitted ? 'Application resubmitted.' : 'Application submitted.',
                entityType: Application::class,
                entityId: $application->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'required_documents' => array_map(fn (DocumentType $t) => $t->value, $requiredDocumentTypes),
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $application,
                eventType: 'submission',
                eventCode: $toStatus === ApplicationStatus::Resubmitted ? 'submission.resubmitted' : 'submission.submitted',
                stage: LifecycleStage::Submitted,
                title: $toStatus === ApplicationStatus::Resubmitted ? 'Application resubmitted' : 'Application submitted',
                description: $toStatus === ApplicationStatus::Resubmitted
                    ? 'Applicant resubmitted the application after amendment.'
                    : 'Applicant submitted the application for processing.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'required_documents' => array_map(fn (DocumentType $t) => $t->value, $requiredDocumentTypes),
                    'payment_status' => $latestPayment?->status?->value ?? null,
                    'payment_method' => $latestPayment?->method?->value ?? null,
                ],
                occurredAt: $now,
            );

            $this->assignVerificationReferenceNumbers($application);

            $application->loadMissing('qualifications');
            foreach ($application->qualifications as $qualification) {
                /** @var Qualification $qualification */
                if ($fromStatus === ApplicationStatus::SentBack) {
                    if ($qualification->verification_state === VerificationState::ReturnedToApplicant) {
                        $this->qualificationCapture->reopenQualificationAfterApplicantAmendment($qualification, $actor);
                    } else {
                        $qualification->forceFill([
                            'verification_state' => VerificationState::AwaitingAssignment,
                            'returned_to_applicant_at' => null,
                        ])->save();
                    }

                    continue;
                }

                if ($fromStatus === ApplicationStatus::Draft && $qualification->verification_state === null) {
                    $qualification->verification_state = VerificationState::AwaitingAssignment;
                    $qualification->save();
                }
            }

            event(new ApplicationSubmitted($application, $actor, $toStatus === ApplicationStatus::Resubmitted));

            return $application;
        });
    }

    /**
     * @return array<int, DocumentType>
     */
    private function requiredDocumentTypes(Application $application): array
    {
        // Application-level required documents are validated in missingDocumentTypes().
        // Qualification-level requirements depend on each qualification item (local/foreign/type).
        return [];
    }

    /**
     * @param  array<int, DocumentType>  $requiredDocumentTypes
     * @return array<int, string>
     */
    private function missingDocumentTypes(Application $application, array $requiredDocumentTypes): array
    {
        $currentDocsByType = $application->documents
            ->filter(fn (QualificationDocument $doc) => (bool) $doc->is_current_version)
            ->groupBy(fn (QualificationDocument $doc) => $doc->document_type?->value ?? (string) $doc->document_type);

        $missing = [];

        $hasIdentity = $currentDocsByType->has(DocumentType::NrcCopy->value)
            || $currentDocsByType->has(DocumentType::PassportCopy->value);

        if (! $hasIdentity) {
            $meta = (array) ($application->metadata ?? []);
            $submittingFor = (string) ($meta['submitting_for'] ?? 'self');
            if ($submittingFor === 'self') {
                $application->loadMissing('applicant.applicantProfile');
                $path = $application->applicant?->applicantProfile?->identity_document_path;
                if (is_string($path) && trim($path) !== '') {
                    $hasIdentity = true;
                }
            }
        }

        if (! $hasIdentity) {
            $missing[] = 'nrc_copy or passport_copy';
        }

        // Qualification-level documents must be present per qualification item.
        $application->loadMissing('qualifications');
        foreach ($application->qualifications as $q) {
            /** @var Qualification $q */
            $hasCertificate = $application->documents
                ->where('is_current_version', true)
                ->where('document_type', DocumentType::CertificateCopy->value)
                ->where('qualification_id', $q->id)
                ->count() > 0;
            if (! $hasCertificate) {
                $missing[] = 'certificate_copy (qualification_id='.$q->id.')';
            }

            $needsTranscript = (bool) ($q->transcript_required ?? false);
            if ($needsTranscript) {
                $hasTranscript = $application->documents
                    ->where('is_current_version', true)
                    ->where('document_type', DocumentType::Transcript->value)
                    ->where('qualification_id', $q->id)
                    ->count() > 0;
                if (! $hasTranscript) {
                    $missing[] = 'transcript (qualification_id='.$q->id.')';
                }
            }
        }

        return $missing;
    }

    private function assertConsentSatisfied(Application $application): void
    {
        $application->loadMissing('qualifications.consentForm', 'qualifications.awardingInstitution.country', 'qualifications.country');

        foreach ($application->qualifications as $q) {
            /** @var Qualification $q */
            $consent = $q->consentForm;
            $instIso = strtoupper((string) (($q->awardingInstitution?->country?->iso_code) ?: ($q->country?->iso_code) ?: ''));
            $institutionIsForeign = $instIso !== '' && ! CountryIso::isZambia($instIso);

            if ($institutionIsForeign) {
                if (! $consent || ! $consent->uploaded_document_id) {
                    throw ValidationException::withMessages([
                        'consent' => 'Each foreign qualification requires a signed consent upload before submission.',
                    ]);
                }
                if ($consent->consent_type !== ConsentType::ForeignUploaded) {
                    throw ValidationException::withMessages([
                        'consent' => 'Foreign consent form is not recorded correctly. Please re-upload the consent form.',
                    ]);
                }
            }
            // Zambian / local awarding: no per-qualification institution consent (wizard declarations cover the applicant).
        }
    }

    /**
     * Each qualification verification item gets a stable pool-wide reference at submit time.
     * Existing numbers are kept on resubmit so correspondence stays consistent; new rows get a new code.
     */
    private function assignVerificationReferenceNumbers(Application $application): void
    {
        $application->loadMissing('qualifications');

        foreach ($application->qualifications->sortBy('id')->values() as $qualification) {
            /** @var Qualification $qualification */
            $existing = trim((string) ($qualification->verification_reference_number ?? ''));
            if ($existing !== '') {
                continue;
            }

            $qualification->verification_reference_number = $this->generateUniqueVerificationReferenceNumber();
            try {
                $qualification->save();
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintViolation($e)) {
                    $qualification->verification_reference_number = $this->generateUniqueVerificationReferenceNumber();
                    $qualification->save();
                } else {
                    throw $e;
                }
            }
        }
    }

    private function generateUniqueVerificationReferenceNumber(): string
    {
        $attempts = 0;

        while ($attempts < 12) {
            $attempts++;
            $candidate = 'ZAQA-Q-'.now()->format('Y').'-'.strtoupper(Str::random(10));

            if (! Qualification::query()->where('verification_reference_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique qualification verification reference number.');
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23000' && in_array((int) $driverCode, [1062, 19], true);
    }

    private function assertWizardDeclarationsComplete(Application $application): void
    {
        $meta = (array) ($application->metadata ?? []);
        $wd = $meta['wizard_declarations'] ?? null;
        if (! is_array($wd)) {
            throw ValidationException::withMessages([
                'declarations' => 'Please complete the Declarations step (terms and accuracy confirmation) before submitting.',
            ]);
        }
        $terms = $wd['terms_accepted_at'] ?? null;
        $confirmed = $wd['information_confirmed_at'] ?? null;
        if (! is_string($terms) || trim($terms) === '' || ! is_string($confirmed) || trim($confirmed) === '') {
            throw ValidationException::withMessages([
                'declarations' => 'Please complete the Declarations step (terms and accuracy confirmation) before submitting.',
            ]);
        }
    }
}
