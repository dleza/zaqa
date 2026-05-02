<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\CertificateSubject;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use App\Support\CountryIso;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationCaptureService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
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
            $awardingInstitutionId = $data['awarding_institution_id'] ?? ($data['awarding_body_id'] ?? null);
            if ($awardingInstitutionId) {
                $inst = AwardingInstitution::query()->with('country')->find((int) $awardingInstitutionId);
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

            $holderIdentity = $this->holderIdentityFromApplication($application, $actor);

            $payload = [
                'awarding_institution_id' => $data['awarding_institution_id'] ?? ($data['awarding_body_id'] ?? null),
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

            // Backward compatibility: if the awarding institution isn't posted as an id/other,
            // accept the legacy awarding_institution_name input.
            if (array_key_exists('awarding_institution_name', $data)) {
                $payload['awarding_institution_name'] = (string) $data['awarding_institution_name'];
            } elseif (! empty($payload['awarding_institution_name_other'])) {
                $payload['awarding_institution_name'] = (string) $payload['awarding_institution_name_other'];
            } else {
                $payload['awarding_institution_name'] = $payload['awarding_institution_name'] ?: ($qualification?->awarding_institution_name ?? '');
            }

            $wasReturnedToApplicant = $qualification
                && ($qualification->verification_state === VerificationState::ReturnedToApplicant);

            if ($qualification && ! $createNew) {
                $qualification->forceFill($payload)->save();
            } else {
                $qualification = Qualification::create(array_merge(['application_id' => $application->id], $payload));
            }

            if ($wasReturnedToApplicant) {
                $qualification->forceFill([
                    'verification_state' => VerificationState::AwaitingAssignment,
                    'returned_to_applicant_at' => null,
                ])->save();

                $this->lifecycle->event(
                    application: $application,
                    eventType: 'submission',
                    eventCodeBase: 'submission.qualification_amended.q'.$qualification->id,
                    stage: LifecycleStage::Review,
                    title: 'Qualification amended',
                    description: 'Applicant updated a qualification item after ZAQA feedback.',
                    visibility: LifecycleVisibility::Both,
                    actor: $actor,
                    comment: null,
                    metadata: [
                        'qualification_id' => $qualification->id,
                    ],
                    occurredAt: now(),
                );
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

            return $qualification;
        });
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
            $holderName = trim((string) $actor->name);
        }

        $idNumber = is_array($verificationSubject)
            ? trim((string) (($verificationSubject['nrc_number'] ?? '') ?: ($verificationSubject['passport_number'] ?? '')))
            : '';

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

            $qualification->subjectResults()->create([
                'certificate_subject_id' => $catalog->id,
                'subject_name' => $catalog->name,
                'grade' => trim((string) ($row['grade'] ?? '')),
                'display_order' => $index,
            ]);
        }
    }
}
