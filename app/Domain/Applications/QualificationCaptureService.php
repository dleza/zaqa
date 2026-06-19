<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Domain\Verification\Events\QualificationCorrectionsSubmitted;
use App\Domain\Verification\QualificationSlaService;
use App\Domain\Verification\VerificationQualificationCorrectionDiff;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\CertificateSubject;
use App\Support\Qualifications\CertificateSubjectGrade;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationTitle;
use App\Models\QualificationType;
use App\Models\User;
use App\Support\Applications\ApplicationSubmissionMode;
use App\Support\Applications\QualificationHolderIdentityResolver;
use App\Support\CountryIso;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationCaptureService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
        private readonly QualificationSlaService $qualificationSla,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertQualification(Application $application, array $data, User $actor): Qualification
    {
        $qualificationTypeId = (int) ($data['qualification_type_id'] ?? 0);
        $qualificationType = QualificationType::query()->whereKey($qualificationTypeId)->firstOrFail();
        $subjectResults = array_key_exists('subject_results', $data) && is_array($data['subject_results'])
            ? $data['subject_results']
            : null;

        return DB::transaction(function () use ($application, $data, $actor, $qualificationType, $qualificationTypeId, $subjectResults) {
            $feeImpactingAmendmentNote = null;

            $qualificationId = (int) ($data['qualification_id'] ?? 0);
            $createNew = (bool) ($data['create_new'] ?? false);
            $qualification = $qualificationId > 0
                ? Qualification::query()->whereKey($qualificationId)->where('application_id', $application->id)->first()
                : null;

            if (! $createNew && ! $qualification) {
                // Backward-compatible behavior: if caller doesn't specify a qualification_id,
                // update the most recently created qualification for this application.
                $qualification = Qualification::query()
                    ->where('application_id', $application->id)
                    ->orderByDesc('id')
                    ->first();
            }

            if ($createNew) {
                ApplicantQualificationAmendmentGuard::assertCanCreateQualification($application);
            } else {
                ApplicantQualificationAmendmentGuard::assertQualificationEditable($application, $qualification);
            }

            $beforeQualification = $qualification?->toArray();
            $beforeSubjects = $qualification?->subjectResults
                ->map(fn ($row) => ['subject_name' => $row->subject_name, 'grade' => $row->grade, 'display_order' => $row->display_order])
                ->values()
                ->all();

            if (array_key_exists('country_id', $data) && $data['country_id']) {
                $country = Country::query()->find((int) $data['country_id']);
                if ($country) {
                    // locality is now stored per qualification; application-level is derived after save
                }
            }

            // Determine foreign/local primarily from the selected awarding institution country (business rule),
            // falling back to the qualification country if institution is not selected yet.
            $isForeignQualification = (bool) ($qualification?->is_foreign_qualification ?? false);
            $resolvedInstitutionId = $this->resolveNumericAwardingInstitutionId(
                $data['awarding_institution_id'] ?? ($data['awarding_body_id'] ?? null),
            );
            if ($resolvedInstitutionId) {
                $inst = AwardingInstitution::query()->with('country')->find($resolvedInstitutionId);
                $iso = strtoupper((string) ($inst?->country?->iso_code ?? ''));
                if ($iso !== '') {
                    $isForeignQualification = ! CountryIso::isZambia($iso);
                }
            } elseif (array_key_exists('country_id', $data) && $data['country_id']) {
                $country = Country::query()->find((int) $data['country_id']);
                $iso = strtoupper((string) ($country?->iso_code ?? ''));
                if ($iso !== '') {
                    $isForeignQualification = ! CountryIso::isZambia($iso);
                }
            }
            $transcriptRequired = (bool) $isForeignQualification || (bool) $qualificationType->requires_subject_results;

            if (ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
                $composed = QualificationHolderIdentityResolver::composeHolderIdentityFromPayload($data);
                $holderIdentity = [
                    'holder_name' => $composed['holder_name'],
                    'nrc_passport_number' => $composed['nrc_passport_number'],
                ];
                $holderIdentityJson = $composed['holder_identity'];
            } else {
                $holderIdentity = $this->holderIdentityFromApplication($application, $actor);
                $holderIdentityJson = null;
            }

            $titleSourceRaw = array_key_exists('qualification_title_source', $data)
                ? trim((string) ($data['qualification_title_source'] ?? ''))
                : '';
            $manualTitleRaw = array_key_exists('applicant_entered_qualification_title', $data)
                ? trim((string) ($data['applicant_entered_qualification_title'] ?? ''))
                : '';

            $payload = [
                'awarding_institution_id' => $resolvedInstitutionId,
                'awarding_institution_name_other' => $data['awarding_institution_name_other'] ?? ($data['awarding_body_name_other'] ?? null),
                'awarding_institution_name' => (string) ($data['awarding_institution_name'] ?? ''),
                'qualification_holder_name' => $holderIdentity['holder_name'],
                'country_id' => $data['country_id'] ?? null,
                'country_name_other' => $data['country_name_other'] ?? null,
                'nrc_passport_number' => $holderIdentity['nrc_passport_number'],
                'certificate_number' => $data['certificate_number'] ?? null,
                'student_number' => $data['student_number'] ?? null,
                'examination_number' => $data['examination_number'] ?? null,
                'title_of_qualification' => (string) $data['title_of_qualification'],
                'names_as_on_qualification_document' => $this->normalizeNamesAsOnQualificationDocument($data['names_as_on_qualification_document'] ?? null),
                'award_date' => (string) $data['award_date'],
                // Legacy string column retained for existing schema reads; stores the ZQF level code.
                'qualification_type' => $qualificationType->zqf_level_code,
                'qualification_type_id' => $qualificationTypeId,
                'is_foreign_qualification' => (bool) $isForeignQualification,
                'transcript_required' => $transcriptRequired,
                'transcript_reason' => $data['transcript_reason'] ?? null,
                'notes' => array_key_exists('notes', $data)
                    ? (($data['notes'] ?? '') !== '' ? (string) $data['notes'] : null)
                    : ($qualification?->notes),
                'raw_subject_results' => $subjectResults,
            ];

            if ($holderIdentityJson !== null) {
                $payload['holder_identity'] = $holderIdentityJson;
            }

            if ($titleSourceRaw !== '' || $manualTitleRaw !== '' || array_key_exists('qualification_title_id', $data)) {
                $this->applyQualificationTitleFields($payload, $data, $titleSourceRaw, $manualTitleRaw);
            }

            // Backward compatibility: if the awarding institution isn't posted as an id/other,
            // accept the legacy awarding_institution_name input.
            if (array_key_exists('awarding_institution_name', $data)) {
                $payload['awarding_institution_name'] = (string) $data['awarding_institution_name'];
            } elseif (! empty($payload['awarding_institution_name_other'])) {
                $payload['awarding_institution_name'] = (string) $payload['awarding_institution_name_other'];
            } else {
                $payload['awarding_institution_name'] = $payload['awarding_institution_name'] ?: ($qualification?->awarding_institution_name ?? '');
            }

            if ($qualification && ! $createNew) {
                $oldTypeId = (int) ($qualification->qualification_type_id ?? 0);
                $oldForeign = (bool) $qualification->is_foreign_qualification;
                if ($oldTypeId !== $qualificationTypeId || $oldForeign !== $isForeignQualification) {
                    $oldTypeName = $oldTypeId > 0
                        ? (string) (QualificationType::query()->whereKey($oldTypeId)->value('name') ?? 'Previous type')
                        : '—';
                    $newTypeName = (string) $qualificationType->name;
                    $parts = ["Qualification type: {$oldTypeName} → {$newTypeName}"];
                    if ($oldForeign !== $isForeignQualification) {
                        $parts[] = $isForeignQualification
                            ? 'Award locality: Zambia → foreign'
                            : 'Award locality: foreign → Zambia';
                    }
                    $feeImpactingAmendmentNote = implode('. ', $parts).'.';
                }
            }

            if ($qualification && ! $createNew) {
                $qualification->forceFill($payload)->save();
            } else {
                $qualification = Qualification::create(array_merge(['application_id' => $application->id], $payload));
            }

            if (is_array($subjectResults)) {
                $this->replaceQualificationSubjectResults($qualification, $subjectResults);
            }

            $qualification->load('subjectResults');

            $afterQualification = $qualification->toArray();
            $afterSubjects = $qualification->subjectResults
                ->map(fn ($row) => ['subject_name' => $row->subject_name, 'grade' => $row->grade, 'display_order' => $row->display_order])
                ->values()
                ->all();

            $this->audit->record(
                eventType: 'qualifications.saved',
                module: 'Qualifications',
                actionName: 'qualification_saved',
                message: 'Qualification captured/updated.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: [
                    'qualification' => $beforeQualification,
                    'subject_results' => $beforeSubjects,
                ],
                afterState: [
                    'qualification' => $afterQualification,
                    'subject_results' => $afterSubjects,
                ],
                metadata: [
                    'application_id' => $application->id,
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $application,
                eventType: 'wizard',
                eventCode: 'wizard.step2.qualification_saved',
                stage: LifecycleStage::Wizard,
                title: 'Qualification details saved',
                description: 'Applicant saved qualification details.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'qualification_id' => $qualification->id,
                    'country_id' => $qualification->country_id,
                    'awarding_institution_id' => $qualification->awarding_institution_id,
                    'qualification_type_id' => $qualification->qualification_type_id,
                ],
                occurredAt: now(),
            );

            // Maintain application-level is_foreign as an aggregate for legacy UI and finance gating.
            $application->loadMissing('qualifications');
            $application->forceFill([
                'is_foreign' => (bool) $application->qualifications->contains(fn (Qualification $q) => (bool) $q->is_foreign_qualification),
            ])->save();

            if ($qualification->service_started_at) {
                $this->qualificationSla->applyQualificationSla($qualification, $qualification->service_started_at);
                $this->qualificationSla->syncApplicationAggregateDeadline($application);
            }

            if ($feeImpactingAmendmentNote !== null) {
                $this->mergeApplicationPendingInvoiceAmendmentReason($application, $feeImpactingAmendmentNote);
            }

            return $qualification;
        });
    }

    private function mergeApplicationPendingInvoiceAmendmentReason(Application $application, string $line): void
    {
        $application->refresh();
        $meta = (array) ($application->metadata ?? []);
        $prev = isset($meta['pending_invoice_amendment_reason']) ? (string) $meta['pending_invoice_amendment_reason'] : '';
        $meta['pending_invoice_amendment_reason'] = $prev !== '' ? $prev.' '.$line : $line;
        $application->forceFill(['metadata' => $meta])->save();
    }

    /**
     * When an applicant saves changes after a per-qualification send-back, route the task back to the
     * officer who sent it (Level 1: reassignment; Level 2: under Level 2 review with owner tracking).
     */
    public function reopenQualificationAfterApplicantAmendment(Qualification $qualification, User $actor): void
    {
        $qualification->refresh();
        if ($qualification->verification_state !== VerificationState::ReturnedToApplicant) {
            return;
        }

        $qualification->loadMissing('application');
        $application = $qualification->application;
        if (! $application) {
            return;
        }

        $sendBackById = $qualification->send_back_by_user_id ? (int) $qualification->send_back_by_user_id : null;
        $reopenLevel = (string) ($qualification->send_back_reopen_level ?? 'level1');
        $officer = $sendBackById ? User::query()->find($sendBackById) : null;
        $officerUnavailableFallback = false;
        $returnedToOfficer = null;

        if (! $sendBackById) {
            $qualification->forceFill([
                'verification_state' => VerificationState::AwaitingAssignment,
                'returned_to_applicant_at' => null,
            ])->save();
        } elseif (! ApplicantQualificationAmendmentGuard::officerCanReceiveReopenedQualification($officer, $reopenLevel)) {
            $officerUnavailableFallback = true;
            $qualification->forceFill([
                'verification_state' => VerificationState::AwaitingAssignment,
                'assigned_verifier_id' => null,
                'assigned_at' => null,
                'returned_to_applicant_at' => null,
                'send_back_by_user_id' => null,
                'send_back_reopen_level' => null,
                'level2_review_owner_id' => null,
            ])->save();
        } elseif ($reopenLevel === 'level2') {
            $returnedToOfficer = $officer;
            $qualification->forceFill([
                'verification_state' => VerificationState::UnderLevel2Review,
                'assigned_verifier_id' => null,
                'assigned_at' => null,
                'returned_to_applicant_at' => null,
                'send_back_by_user_id' => null,
                'send_back_reopen_level' => null,
                'level2_review_owner_id' => (int) $sendBackById,
            ])->save();
        } else {
            $returnedToOfficer = $officer;
            $qualification->forceFill([
                'verification_state' => VerificationState::UnderLevel1Review,
                'assigned_verifier_id' => (int) $sendBackById,
                'assigned_at' => now(),
                'returned_to_applicant_at' => null,
                'send_back_by_user_id' => null,
                'send_back_reopen_level' => null,
                'level2_review_owner_id' => null,
            ])->save();
        }

        $startedAt = now();
        $this->qualificationSla->applyQualificationSla($qualification, $startedAt);
        $this->qualificationSla->syncApplicationAggregateDeadline($application);

        $this->lifecycle->event(
            application: $application,
            eventType: 'submission',
            eventCodeBase: 'submission.qualification_amended.q'.$qualification->id,
            stage: LifecycleStage::Review,
            title: 'Qualification amended',
            description: 'Applicant submitted corrections for a qualification item after ZAQA feedback.',
            visibility: LifecycleVisibility::Both,
            actor: $actor,
            comment: null,
            metadata: [
                'qualification_id' => $qualification->id,
                'qualification_title' => $qualification->title_of_qualification,
            ],
            occurredAt: now(),
        );

        if ($officerUnavailableFallback) {
            $this->lifecycle->event(
                application: $application,
                eventType: 'review',
                eventCodeBase: 'review.qualification_reopen_fallback.q'.$qualification->id,
                stage: LifecycleStage::Review,
                title: 'Returned to assignment pool',
                description: 'Original reviewer unavailable; returned to assignment pool.',
                visibility: LifecycleVisibility::Internal,
                actor: $actor,
                comment: null,
                metadata: [
                    'qualification_id' => $qualification->id,
                    'original_send_back_by_user_id' => $sendBackById,
                ],
                occurredAt: now(),
            );
        }

        event(new QualificationCorrectionsSubmitted(
            qualification: $qualification->fresh(),
            application: $application->fresh(),
            applicant: $actor,
            returnedToOfficer: $returnedToOfficer,
            officerUnavailableFallback: $officerUnavailableFallback,
        ));
    }

    /**
     * Holder name and primary identity number always come from the application “verification subject”
     * (new application + applicant step), not per qualification row.
     *
     * @return array{holder_name: string, nrc_passport_number: string}
     */
    private function holderIdentityFromApplication(Application $application, User $actor): array
    {
        $verificationSubject = null;
        $meta = $application->metadata;
        if (is_array($meta)) {
            $candidate = $meta['verification_subject'] ?? null;
            $verificationSubject = is_array($candidate) ? $candidate : null;
        } elseif ($meta instanceof \ArrayAccess) {
            $candidate = $meta['verification_subject'] ?? null;
            $verificationSubject = is_array($candidate) ? $candidate : null;
        }

        $holderName = is_array($verificationSubject) ? trim((string) ($verificationSubject['full_name'] ?? '')) : '';
        if ($holderName === '') {
            if (is_array($verificationSubject)) {
                $holderName = trim((string) implode(' ', array_filter([
                    (string) ($verificationSubject['first_name'] ?? ''),
                    (string) ($verificationSubject['other_names'] ?? ''),
                    (string) ($verificationSubject['last_name'] ?? ''),
                ], fn ($v) => trim((string) $v) !== '')));
            }
        }
        if ($holderName === '') {
            $holderName = trim((string) $actor->name);
        }

        $idNumber = '';
        if (is_array($verificationSubject)) {
            $identityType = strtolower(trim((string) ($verificationSubject['identity_type'] ?? '')));
            if ($identityType === 'passport') {
                $idNumber = trim((string) ($verificationSubject['passport_number'] ?? ''));
            } else {
                $idNumber = trim((string) ($verificationSubject['nrc_number'] ?? ''));
            }

            if ($idNumber === '') {
                $idNumber = trim((string) (($verificationSubject['nrc_number'] ?? '') ?: ($verificationSubject['passport_number'] ?? '')));
            }
        }

        return [
            'holder_name' => $holderName,
            'nrc_passport_number' => $idNumber,
        ];
    }

    /**
     * Save only the qualification's non-subject fields (wizard step).
     *
     * @param  array<string, mixed>  $data
     */
    public function upsertQualificationDetails(Application $application, array $data, User $actor): Qualification
    {
        unset($data['subject_results']);

        return $this->upsertQualification($application, $data, $actor);
    }

    /**
     * Admin verification UI: correct qualification data entered by the applicant. Does not change
     * workflow state, assignments, fees, or verification reference numbers. Changes are audited.
     *
     * @param  array<string, mixed>  $data
     */
    public function adminVerificationCorrectionWouldChange(Qualification $qualification, array $data): bool
    {
        return $this->previewAdminVerificationCorrectionChanges($qualification, $data) !== [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{field: string, label: string, from: mixed, to: mixed}>
     */
    public function previewAdminVerificationCorrectionChanges(Qualification $qualification, array $data): array
    {
        $qualification->loadMissing(['subjectResults', 'awardingInstitution.country', 'country']);

        $beforeQualification = $qualification->toArray();
        if ($qualification->award_date) {
            $beforeQualification['award_date'] = $qualification->award_date->format('Y-m-d');
        }
        $beforeSubjects = $qualification->subjectResults
            ->map(fn ($row) => ['subject_name' => $row->subject_name, 'grade' => $row->grade, 'display_order' => $row->display_order])
            ->values()
            ->all();

        $projection = $this->buildAdminVerificationCorrectionProjection($qualification, $data);
        $afterQualification = $projection['qualification'];
        if (! empty($afterQualification['award_date'])) {
            $afterQualification['award_date'] = \Illuminate\Support\Carbon::parse((string) $afterQualification['award_date'])->format('Y-m-d');
        }

        return VerificationQualificationCorrectionDiff::build(
            $beforeQualification,
            $afterQualification,
            $beforeSubjects,
            $projection['subjects'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function adminVerificationCorrection(Qualification $qualification, array $data, User $actor): Qualification
    {
        return DB::transaction(function () use ($qualification, $data, $actor) {
            $qualification->refresh();
            $qualification->load(['application', 'subjectResults', 'awardingInstitution.country', 'country']);
            $application = $qualification->application;
            if (! $application) {
                throw ValidationException::withMessages([
                    'qualification' => 'Application is missing for this qualification.',
                ]);
            }

            $beforeQualification = $qualification->toArray();
            $beforeSubjects = $qualification->subjectResults
                ->map(fn ($row) => ['subject_name' => $row->subject_name, 'grade' => $row->grade, 'display_order' => $row->display_order])
                ->values()
                ->all();

            $workflowSnapshot = [
                'verification_state' => $qualification->verification_state?->value ?? (string) ($qualification->verification_state ?? ''),
                'assigned_verifier_id' => $qualification->assigned_verifier_id,
                'verification_reference_number' => $qualification->verification_reference_number,
                'fee_amount_cents' => $qualification->fee_amount_cents,
                'fee_currency' => $qualification->fee_currency,
            ];

            $projection = $this->buildAdminVerificationCorrectionProjection($qualification, $data);
            $payload = $projection['payload'];

            $qualification->forceFill($payload)->save();

            $this->replaceQualificationSubjectResults($qualification, is_array($data['subject_results'] ?? null) ? $data['subject_results'] : []);

            $qualification->load('subjectResults');

            $afterQualification = $qualification->fresh()->toArray();
            $afterSubjects = $qualification->subjectResults
                ->map(fn ($row) => ['subject_name' => $row->subject_name, 'grade' => $row->grade, 'display_order' => $row->display_order])
                ->values()
                ->all();

            $afterWorkflowSnapshot = [
                'verification_state' => $qualification->verification_state?->value ?? (string) ($qualification->verification_state ?? ''),
                'assigned_verifier_id' => $qualification->assigned_verifier_id,
                'verification_reference_number' => $qualification->verification_reference_number,
                'fee_amount_cents' => $qualification->fee_amount_cents,
                'fee_currency' => $qualification->fee_currency,
            ];
            if ($afterWorkflowSnapshot !== $workflowSnapshot) {
                throw ValidationException::withMessages([
                    'qualification' => 'Correction would have altered verification workflow state.',
                ]);
            }

            $fieldChanges = VerificationQualificationCorrectionDiff::build(
                $beforeQualification,
                $afterQualification,
                $beforeSubjects,
                $afterSubjects,
            );

            $correctionNoteRaw = array_key_exists('correction_note', $data) ? trim((string) $data['correction_note']) : '';
            $correctionNote = $correctionNoteRaw !== '' ? $correctionNoteRaw : null;

            $this->audit->record(
                eventType: 'verification.qualification_corrected',
                module: 'Verification',
                actionName: 'qualification_corrected',
                message: $fieldChanges === []
                    ? 'Verifier saved qualification details with no field changes.'
                    : 'Verifier corrected qualification data submitted for review.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: [
                    'qualification' => $beforeQualification,
                    'subject_results' => $beforeSubjects,
                    'workflow' => $workflowSnapshot,
                ],
                afterState: [
                    'qualification' => $afterQualification,
                    'subject_results' => $afterSubjects,
                    'workflow' => $afterWorkflowSnapshot,
                ],
                metadata: [
                    'application_id' => $application->id,
                    'correction_note' => $correctionNote,
                    'field_changes' => $fieldChanges,
                    'source' => 'admin_verification_edit',
                ],
                actor: $actor,
            );

            $application->loadMissing('qualifications');
            $application->forceFill([
                'is_foreign' => (bool) $application->qualifications->contains(fn (Qualification $q) => (bool) $q->is_foreign_qualification),
            ])->save();

            return $qualification->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payload: array<string, mixed>, qualification: array<string, mixed>, subjects: list<array{subject_name: string|null, grade: string|null, display_order: int|null}>}
     */
    private function buildAdminVerificationCorrectionProjection(Qualification $qualification, array $data): array
    {
        $qualificationTypeId = (int) $data['qualification_type_id'];
        $qualificationType = QualificationType::query()->whereKey($qualificationTypeId)->firstOrFail();

        $resolvedInstitutionId = $this->resolveNumericAwardingInstitutionId($data['awarding_institution_id'] ?? null);

        $isForeignQualification = (bool) $qualification->is_foreign_qualification;
        if ($resolvedInstitutionId) {
            $inst = AwardingInstitution::query()->with('country')->find($resolvedInstitutionId);
            $iso = strtoupper((string) ($inst?->country?->iso_code ?? ''));
            if ($iso !== '') {
                $isForeignQualification = ! CountryIso::isZambia($iso);
            }
        } elseif (! empty($data['country_id'])) {
            $country = Country::query()->find((int) $data['country_id']);
            $iso = strtoupper((string) ($country?->iso_code ?? ''));
            if ($iso !== '') {
                $isForeignQualification = ! CountryIso::isZambia($iso);
            }
        }

        $transcriptRequired = (bool) $isForeignQualification || (bool) $qualificationType->requires_subject_results;

        $holderName = trim((string) ($data['qualification_holder_name'] ?? ''));
        if ($holderName === '') {
            $holderName = (string) ($qualification->qualification_holder_name ?? '');
        }
        $nrc = trim((string) ($data['nrc_passport_number'] ?? ''));
        if ($nrc === '') {
            $nrc = (string) ($qualification->nrc_passport_number ?? '');
        }

        $awardingInstitutionNameOther = trim((string) ($data['awarding_institution_name_other'] ?? ''));
        $awardingInstitutionNameInput = trim((string) ($data['awarding_institution_name'] ?? ''));

        $awardingInstitutionName = $awardingInstitutionNameInput;
        if ($awardingInstitutionName === '' && $awardingInstitutionNameOther !== '') {
            $awardingInstitutionName = $awardingInstitutionNameOther;
        }
        if ($awardingInstitutionName === '' && $resolvedInstitutionId) {
            $awardingInstitutionName = (string) (AwardingInstitution::query()->whereKey($resolvedInstitutionId)->value('name') ?? '');
        }
        if ($awardingInstitutionName === '') {
            $awardingInstitutionName = (string) ($qualification->awarding_institution_name ?? '');
        }

        $notesVal = array_key_exists('notes', $data)
            ? (($data['notes'] ?? '') !== '' ? (string) $data['notes'] : null)
            : $qualification->notes;

        $countryNameOther = trim((string) ($data['country_name_other'] ?? ''));

        $titleSourceRaw = array_key_exists('qualification_title_source', $data)
            ? trim((string) ($data['qualification_title_source'] ?? ''))
            : '';
        $manualTitleRaw = array_key_exists('applicant_entered_qualification_title', $data)
            ? trim((string) ($data['applicant_entered_qualification_title'] ?? ''))
            : '';

        $payload = [
            'awarding_institution_id' => $resolvedInstitutionId,
            'awarding_institution_name_other' => $awardingInstitutionNameOther !== '' ? $awardingInstitutionNameOther : null,
            'awarding_institution_name' => $awardingInstitutionName,
            'qualification_holder_name' => $holderName,
            'country_id' => (int) $data['country_id'],
            'country_name_other' => $countryNameOther !== '' ? $countryNameOther : null,
            'nrc_passport_number' => $nrc,
            'certificate_number' => trim((string) ($data['certificate_number'] ?? '')) ?: null,
            'student_number' => trim((string) ($data['student_number'] ?? '')) ?: null,
            'examination_number' => trim((string) ($data['examination_number'] ?? '')) ?: null,
            'title_of_qualification' => (string) $data['title_of_qualification'],
            'names_as_on_qualification_document' => array_key_exists('names_as_on_qualification_document', $data)
                ? $this->normalizeNamesAsOnQualificationDocument($data['names_as_on_qualification_document'])
                : (string) ($qualification->names_as_on_qualification_document ?? ''),
            'award_date' => (string) $data['award_date'],
            'qualification_type' => $qualificationType->zqf_level_code,
            'qualification_type_id' => $qualificationTypeId,
            'is_foreign_qualification' => $isForeignQualification,
            'transcript_required' => $transcriptRequired,
            'transcript_reason' => array_key_exists('transcript_reason', $data)
                ? ((($data['transcript_reason'] ?? '') !== '') ? (string) $data['transcript_reason'] : null)
                : $qualification->transcript_reason,
            'notes' => $notesVal,
        ];

        if ($titleSourceRaw !== '' || $manualTitleRaw !== '' || array_key_exists('qualification_title_id', $data)) {
            $this->applyQualificationTitleFields($payload, $data, $titleSourceRaw, $manualTitleRaw);
        }

        $subjects = $this->projectAdminVerificationSubjectResults(
            is_array($data['subject_results'] ?? null) ? $data['subject_results'] : [],
        );

        return [
            'payload' => $payload,
            'qualification' => array_merge($qualification->toArray(), $payload),
            'subjects' => $subjects,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{subject_name: string|null, grade: string|null, display_order: int|null}>
     */
    private function projectAdminVerificationSubjectResults(array $rows): array
    {
        $out = [];

        foreach (array_values($rows) as $index => $row) {
            $certificateSubjectId = (int) ($row['certificate_subject_id'] ?? 0);
            if ($certificateSubjectId < 1) {
                continue;
            }

            $catalog = CertificateSubject::query()
                ->whereKey($certificateSubjectId)
                ->where('is_active', true)
                ->first();

            if (! $catalog) {
                continue;
            }

            $normalizedGrade = CertificateSubjectGrade::normalize($row['grade'] ?? null);
            if ($normalizedGrade === null) {
                continue;
            }

            $out[] = [
                'subject_name' => $catalog->name,
                'grade' => $normalizedGrade,
                'display_order' => $index,
            ];
        }

        return $out;
    }

    private function resolveNumericAwardingInstitutionId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw) && strtolower($raw) === 'other') {
            return null;
        }
        if (! is_numeric($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    /**
     * Save only subject results (wizard step). Requires an existing Qualification row.
     *
     * @param  array<string, mixed>  $subjectResults
     */
    public function upsertSubjectResults(Application $application, array $subjectResults, User $actor): Qualification
    {
        return DB::transaction(function () use ($application, $subjectResults, $actor) {
            $qualificationId = (int) ($subjectResults['qualification_id'] ?? 0);
            $rows = $subjectResults;
            if (array_key_exists('subject_results', $subjectResults) && is_array($subjectResults['subject_results'])) {
                $rows = $subjectResults['subject_results'];
            }

            $qualification = Qualification::query()
                ->whereKey($qualificationId)
                ->where('application_id', $application->id)
                ->firstOrFail();

            $beforeSubjects = $qualification->subjectResults
                ->map(fn ($row) => ['subject_name' => $row->subject_name, 'grade' => $row->grade, 'display_order' => $row->display_order])
                ->values()
                ->all();

            $this->replaceQualificationSubjectResults($qualification, $rows);

            $qualification->load('subjectResults');

            $afterSubjects = $qualification->subjectResults
                ->map(fn ($row) => ['subject_name' => $row->subject_name, 'grade' => $row->grade, 'display_order' => $row->display_order])
                ->values()
                ->all();

            $this->audit->record(
                eventType: 'qualifications.subject_results_saved',
                module: 'Qualifications',
                actionName: 'subject_results_saved',
                message: 'Subject results captured/updated.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: [
                    'subject_results' => $beforeSubjects,
                ],
                afterState: [
                    'subject_results' => $afterSubjects,
                ],
                metadata: [
                    'application_id' => $application->id,
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $application,
                eventType: 'wizard',
                eventCode: 'wizard.subjects.saved',
                stage: LifecycleStage::Wizard,
                title: 'Subject results saved',
                description: 'Applicant saved subject results.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'qualification_id' => $qualification->id,
                    'rows' => count($rows),
                ],
                occurredAt: now(),
            );

            return $qualification;
        });
    }

    /**
     * Persist subject rows from the managed catalog (certificate_subjects).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function replaceQualificationSubjectResults(Qualification $qualification, array $rows): void
    {
        $qualification->subjectResults()->delete();

        foreach (array_values($rows) as $index => $row) {
            $certificateSubjectId = (int) ($row['certificate_subject_id'] ?? 0);
            if ($certificateSubjectId < 1) {
                continue;
            }

            $catalog = CertificateSubject::query()
                ->whereKey($certificateSubjectId)
                ->where('is_active', true)
                ->first();

            if (! $catalog) {
                throw ValidationException::withMessages([
                    'subject_results' => 'One or more selected subjects are invalid or inactive.',
                ]);
            }

            $normalizedGrade = CertificateSubjectGrade::normalize($row['grade'] ?? null);
            if ($normalizedGrade === null) {
                throw ValidationException::withMessages([
                    'subject_results' => 'Please select a valid grade.',
                ]);
            }

            $qualification->subjectResults()->create([
                'certificate_subject_id' => $catalog->id,
                'subject_name' => $catalog->name,
                'grade' => $normalizedGrade,
                'display_order' => $index,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    private function applyQualificationTitleFields(array &$payload, array $data, string $titleSourceRaw, string $manualTitleRaw): void
    {
        $source = $titleSourceRaw !== '' ? $titleSourceRaw : ($manualTitleRaw !== '' ? 'other' : 'catalog');

        if ($source === 'other') {
            $payload['qualification_title_source'] = 'other';
            $payload['applicant_entered_qualification_title'] = $manualTitleRaw !== '' ? $manualTitleRaw : null;
            $payload['qualification_title_id'] = null;
            $payload['title_of_qualification'] = $manualTitleRaw !== ''
                ? $manualTitleRaw
                : (string) ($data['title_of_qualification'] ?? '');

            return;
        }

        $titleId = (int) ($data['qualification_title_id'] ?? 0);
        $master = $titleId > 0 ? QualificationTitle::query()->find($titleId) : null;

        $payload['qualification_title_source'] = 'catalog';
        $payload['applicant_entered_qualification_title'] = null;
        $payload['qualification_title_id'] = $master?->id;
        $payload['title_of_qualification'] = $master?->name ?? (string) ($data['title_of_qualification'] ?? '');
    }

    private function normalizeNamesAsOnQualificationDocument(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
