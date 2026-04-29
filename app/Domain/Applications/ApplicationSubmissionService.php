<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogService;
use App\Domain\Applications\Events\ApplicationSubmitted;
use App\Enums\ApplicationStatus;
use App\Enums\ConsentType;
use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\QualificationDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationSubmissionService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
    )
    {
    }

    public function submit(Application $application, User $actor): Application
    {
        return DB::transaction(function () use ($application, $actor) {
            $application->refresh();
            $application->load(['qualification', 'documents', 'consentForm', 'invoice', 'payments']);

            if (! in_array($application->current_status, [ApplicationStatus::Draft, ApplicationStatus::SentBack], true)) {
                throw ValidationException::withMessages([
                    'application' => 'This application cannot be submitted in its current state.',
                ]);
            }

            if (! $application->qualification) {
                throw ValidationException::withMessages([
                    'qualification' => 'Qualification details are required before submission.',
                ]);
            }

            $holderName = trim((string) ($application->qualification->qualification_holder_name ?? ''));
            $holderId = trim((string) ($application->qualification->nrc_passport_number ?? ''));
            if ($holderName === '' || $holderId === '') {
                $this->audit->record(
                    eventType: 'applications.submission_blocked_due_to_missing_holder_identity',
                    module: 'Applications',
                    actionName: 'submission_blocked',
                    message: 'Submission blocked: missing qualification holder identity data.',
                    entityType: Application::class,
                    entityId: $application->id,
                    metadata: [
                        'missing_holder_name' => $holderName === '',
                        'missing_holder_identity' => $holderId === '',
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

            $invoicePaid = $application->invoice && $application->invoice->status === InvoiceStatus::Paid;
            $paymentConfirmed = $invoicePaid || $application->payments->contains(fn ($p) => $p->status === PaymentStatus::Confirmed);
            if (! $paymentConfirmed) {
                $latestPayment = $application->payments->sortByDesc('id')->first();
                $this->audit->record(
                    eventType: 'applications.submission_blocked_due_to_payment',
                    module: 'Applications',
                    actionName: 'submission_blocked',
                    message: 'Submission blocked: payment not confirmed.',
                    entityType: Application::class,
                    entityId: $application->id,
                    metadata: [
                        'invoice_status' => $application->invoice?->status?->value ?? null,
                        'payment_status' => $latestPayment?->status?->value ?? null,
                        'payment_method' => $latestPayment?->method?->value ?? null,
                    ],
                    actor: $actor,
                );

                throw ValidationException::withMessages([
                    'payment' => 'Payment must be confirmed before submission.',
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

            event(new ApplicationSubmitted($application, $actor, $toStatus === ApplicationStatus::Resubmitted));

            return $application;
        });
    }

    /**
     * @return array<int, DocumentType>
     */
    private function requiredDocumentTypes(Application $application): array
    {
        $required = [
            DocumentType::CertificateCopy,
        ];

        if ((bool) $application->is_foreign) {
            $required[] = DocumentType::Transcript;
        }

        if ((bool) $application->is_foreign) {
            $required[] = DocumentType::ConsentFormSigned;
        }

        return $required;
    }

    /**
     * @param array<int, DocumentType> $requiredDocumentTypes
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
            $missing[] = 'nrc_copy or passport_copy';
        }

        foreach ($requiredDocumentTypes as $type) {
            if (! $currentDocsByType->has($type->value)) {
                $missing[] = $type->value;
            }
        }

        return $missing;
    }

    private function assertConsentSatisfied(Application $application): void
    {
        if ((bool) $application->is_foreign) {
            if (
                ! $application->consentForm
                || ! $application->consentForm->uploaded_document_id
                || ! $application->consentForm->zaqa_uploaded_document_id
            ) {
                throw ValidationException::withMessages([
                    'consent' => 'Foreign applications require both the awarding institution consent and the ZAQA consent uploads before submission.',
                ]);
            }

            if ($application->consentForm->consent_type !== ConsentType::ForeignUploaded) {
                throw ValidationException::withMessages([
                    'consent' => 'Foreign consent form is not recorded correctly. Please re-upload the consent form.',
                ]);
            }

            return;
        }

        if (! $application->consentForm || ! $application->consentForm->agreed_at) {
            throw ValidationException::withMessages([
                'consent' => 'You must accept the local embedded consent before submission.',
            ]);
        }

        if ($application->consentForm->consent_type !== ConsentType::LocalEmbedded) {
            throw ValidationException::withMessages([
                'consent' => 'Local embedded consent is not recorded correctly. Please accept the consent again.',
            ]);
        }
    }
}
