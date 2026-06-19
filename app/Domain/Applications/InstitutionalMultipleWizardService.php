<?php

namespace App\Domain\Applications;

use App\Domain\Documents\QualificationDocumentEvidence;
use App\Enums\DocumentType;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\User;
use App\Support\Applications\ApplicationSubmissionMode;

class InstitutionalMultipleWizardService
{
    public function __construct(
        private readonly ApplicationSubmissionReadinessService $readiness,
    ) {}

    /**
     * @return array{current_step: array<string, mixed>|null, edit_href: string|null}
     */
    public function wizardSummary(Application $application, User $user): array
    {
        if (! ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
            return ['current_step' => null, 'edit_href' => null];
        }

        if (! $user->can('update', $application)) {
            return ['current_step' => null, 'edit_href' => null];
        }

        $applicationInfoDone = $this->applicationInfoComplete($application);
        $qualificationRecordsDone = $this->qualificationRecordsComplete($application, $user);
        $reviewDone = $qualificationRecordsDone;
        $declarationsDone = $this->declarationsComplete($application);
        $paymentDone = $this->paymentComplete($application);

        $steps = [
            ['key' => 'application_info', 'label' => 'Application information', 'done' => $applicationInfoDone],
            ['key' => 'qualification_records', 'label' => 'Qualification records', 'done' => $qualificationRecordsDone],
            ['key' => 'review', 'label' => 'Review & submit', 'done' => $reviewDone],
            ['key' => 'consent', 'label' => 'Consent / declaration', 'done' => $declarationsDone],
            ['key' => 'payment', 'label' => 'Payment', 'done' => $paymentDone],
        ];

        $currentIndex = 0;
        foreach ($steps as $idx => $step) {
            if (! (bool) $step['done']) {
                $currentIndex = $idx;
                break;
            }
            $currentIndex = $idx;
        }

        $current = $steps[$currentIndex] ?? $steps[0];

        return [
            'current_step' => [
                'index' => $currentIndex + 1,
                'total' => count($steps),
                'key' => $current['key'],
                'label' => $current['label'],
                'done' => (bool) $current['done'],
            ],
            'edit_href' => route('applicant.applications.multiple.edit', [
                'application' => $application->id,
                'step' => $current['key'],
            ]),
        ];
    }

    public function applicationInfoComplete(Application $application): bool
    {
        return true;
    }

    public function qualificationRecordsComplete(Application $application, User $user): bool
    {
        $application->loadMissing(['qualifications', 'documents', 'applicant']);

        if ($application->qualifications->count() < 1) {
            return false;
        }

        try {
            $this->readiness->assertReadyForPayment($application, $user);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function declarationsComplete(Application $application): bool
    {
        $meta = (array) ($application->metadata ?? []);
        $wd = $meta['wizard_declarations'] ?? null;
        if (! is_array($wd)) {
            return false;
        }

        $termsAt = $wd['terms_accepted_at'] ?? null;
        $confirmedAt = $wd['information_confirmed_at'] ?? null;

        return is_string($termsAt) && trim($termsAt) !== ''
            && is_string($confirmedAt) && trim($confirmedAt) !== '';
    }

    public function paymentComplete(Application $application): bool
    {
        if ((bool) $application->paid_at) {
            return true;
        }

        return app(\App\Domain\Payments\ApplicationPaymentSatisfaction::class)->isSatisfied($application);
    }

    /**
     * @return array<int, string>
     */
    public function missingItemsForReview(Application $application): array
    {
        $application->loadMissing(['qualifications', 'documents', 'applicant']);

        $missing = [];

        if ($application->qualifications->count() < 1) {
            $missing[] = 'Add at least one qualification record.';

            return $missing;
        }

        foreach ($application->qualifications as $qualification) {
            /** @var Qualification $qualification */
            $label = trim((string) ($qualification->title_of_qualification ?? '')) ?: 'Qualification #'.$qualification->id;
            $holder = trim((string) ($qualification->qualification_holder_name ?? ''));
            if ($holder === '') {
                $missing[] = "{$label}: qualification holder name is required.";
            }
            if (trim((string) ($qualification->nrc_passport_number ?? '')) === '') {
                $missing[] = "{$label}: NRC/passport number is required.";
            }

            $hasCertificate = $application->documents
                ->filter(fn (QualificationDocument $doc) => QualificationDocumentEvidence::isActiveEvidence($doc))
                ->contains(fn (QualificationDocument $doc) => (int) ($doc->qualification_id ?? 0) === (int) $qualification->id
                    && ($doc->document_type?->value ?? (string) $doc->document_type) === DocumentType::CertificateCopy->value);
            if (! $hasCertificate) {
                $missing[] = "{$label}: qualification certificate/document is required.";
            }

            $hasIdentity = $application->documents
                ->filter(fn (QualificationDocument $doc) => QualificationDocumentEvidence::isActiveEvidence($doc))
                ->contains(fn (QualificationDocument $doc) => (int) ($doc->qualification_id ?? 0) === (int) $qualification->id
                    && in_array($doc->document_type?->value ?? (string) $doc->document_type, [
                        DocumentType::NrcCopy->value,
                        DocumentType::PassportCopy->value,
                    ], true));
            if (! $hasIdentity) {
                $missing[] = "{$label}: NRC or passport copy is required.";
            }
        }

        return $missing;
    }
}
