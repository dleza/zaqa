<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Models\Country;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class QualificationCaptureService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
    )
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsertQualification(Application $application, array $data, User $actor): Qualification
    {
        $qualificationTypeId = (int) ($data['qualification_type_id'] ?? 0);
        $qualificationType = QualificationType::query()->whereKey($qualificationTypeId)->firstOrFail();
        $subjectResults = array_key_exists('subject_results', $data) && is_array($data['subject_results'])
            ? $data['subject_results']
            : null;

        return DB::transaction(function () use ($application, $data, $actor, $qualificationType, $qualificationTypeId, $subjectResults) {
            $qualification = Qualification::query()
                ->where('application_id', $application->id)
                ->first();

            $application->loadMissing('invoice');
            if ($application->invoice && $qualification) {
                $existingTypeId = (int) ($qualification->qualification_type_id ?? 0);
                if ($existingTypeId > 0 && $existingTypeId !== $qualificationTypeId) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'qualification_type_id' => 'Qualification type cannot be changed after an invoice has been generated.',
                    ]);
                }
            }

            $beforeQualification = $qualification?->toArray();
            $beforeSubjects = $qualification?->subjectResults
                ->map(fn ($row) => ['subject_name' => $row->subject_name, 'grade' => $row->grade, 'display_order' => $row->display_order])
                ->values()
                ->all();

            if (array_key_exists('country_id', $data) && $data['country_id']) {
                $country = Country::query()->find((int) $data['country_id']);
                if ($country) {
                    $application->is_foreign = strtoupper((string) $country->iso_code) !== 'ZMB';
                    $application->save();
                }
            }

            // Transcript uploads are optional in the applicant portal. We keep this flag for legacy display/analytics,
            // but it should not be used to gate submission.
            $transcriptRequired = (bool) $application->is_foreign || (bool) $qualificationType->requires_subject_results;

            $verificationSubject = null;
            $meta = $application->metadata;
            if ($meta instanceof \ArrayAccess) {
                $candidate = $meta['verification_subject'] ?? null;
                $verificationSubject = is_array($candidate) ? $candidate : null;
            }

            $defaultHolderName = is_array($verificationSubject) ? (string) ($verificationSubject['full_name'] ?? '') : '';
            $defaultHolderName = trim($defaultHolderName) !== '' ? $defaultHolderName : $actor->name;
            $defaultIdNumber = is_array($verificationSubject)
                ? trim((string) (($verificationSubject['nrc_number'] ?? '') ?: ($verificationSubject['passport_number'] ?? '')))
                : '';

            $payload = [
                'awarding_institution_id' => $data['awarding_institution_id'] ?? ($data['awarding_body_id'] ?? null),
                'awarding_institution_name_other' => $data['awarding_institution_name_other'] ?? ($data['awarding_body_name_other'] ?? null),
                'awarding_institution_name' => (string) ($data['awarding_institution_name'] ?? ''),
                'qualification_holder_name' => (string) ($data['qualification_holder_name'] ?? $defaultHolderName),
                'country_id' => $data['country_id'] ?? null,
                'country_name_other' => $data['country_name_other'] ?? null,
                'nrc_passport_number' => (string) ($data['nrc_passport_number'] ?? $defaultIdNumber),
                'certificate_number' => $data['certificate_number'] ?? null,
                'student_number' => $data['student_number'] ?? null,
                'examination_number' => $data['examination_number'] ?? null,
                'title_of_qualification' => (string) $data['title_of_qualification'],
                'award_date' => (string) $data['award_date'],
                // Legacy string column retained for existing schema reads; stores the ZQF level code.
                'qualification_type' => $qualificationType->zqf_level_code,
                'qualification_type_id' => $qualificationTypeId,
                'transcript_required' => $transcriptRequired,
                'transcript_reason' => $data['transcript_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
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

            // Preserve existing values if this step does not post these fields anymore.
            if (! array_key_exists('qualification_holder_name', $data) && $qualification?->qualification_holder_name) {
                $payload['qualification_holder_name'] = $qualification->qualification_holder_name;
            }
            if (! array_key_exists('nrc_passport_number', $data) && $qualification?->nrc_passport_number) {
                $payload['nrc_passport_number'] = $qualification->nrc_passport_number;
            }

            $qualification = Qualification::updateOrCreate(
                ['application_id' => $application->id],
                $payload,
            );

            if (is_array($subjectResults)) {
                $qualification->subjectResults()->delete();
                foreach (array_values($subjectResults) as $index => $row) {
                    $qualification->subjectResults()->create([
                        'subject_name' => (string) ($row['subject_name'] ?? ''),
                        'grade' => (string) ($row['grade'] ?? ''),
                        'display_order' => $index,
                    ]);
                }
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

            return $qualification;
        });
    }

    /**
     * Save only the qualification's non-subject fields (wizard step).
     *
     * @param array<string, mixed> $data
     */
    public function upsertQualificationDetails(Application $application, array $data, User $actor): Qualification
    {
        unset($data['subject_results']);

        return $this->upsertQualification($application, $data, $actor);
    }

    /**
     * Save only subject results (wizard step). Requires an existing Qualification row.
     *
     * @param array<string, mixed> $subjectResults
     */
    public function upsertSubjectResults(Application $application, array $subjectResults, User $actor): Qualification
    {
        return DB::transaction(function () use ($application, $subjectResults, $actor) {
            $qualification = Qualification::query()
                ->where('application_id', $application->id)
                ->firstOrFail();

            $beforeSubjects = $qualification->subjectResults
                ->map(fn ($row) => ['subject_name' => $row->subject_name, 'grade' => $row->grade, 'display_order' => $row->display_order])
                ->values()
                ->all();

            $qualification->subjectResults()->delete();

            foreach (array_values($subjectResults) as $index => $row) {
                $qualification->subjectResults()->create([
                    'subject_name' => (string) ($row['subject_name'] ?? ''),
                    'grade' => (string) ($row['grade'] ?? ''),
                    'display_order' => $index,
                ]);
            }

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
                    'rows' => count($subjectResults),
                ],
                occurredAt: now(),
            );

            return $qualification;
        });
    }
}
