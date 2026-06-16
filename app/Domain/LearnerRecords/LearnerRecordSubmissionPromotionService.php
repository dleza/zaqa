<?php

namespace App\Domain\LearnerRecords;

use App\Enums\LearnerRecordSourceType;
use App\Enums\LearnerRecordSubmissionSourceType;
use App\Models\LearnerRecord;
use App\Models\LearnerRecordSubmission;
use Illuminate\Support\Facades\DB;

class LearnerRecordSubmissionPromotionService
{
    /**
     * @return array{record: LearnerRecord, created: bool}
     */
    public function promoteAsNew(LearnerRecordSubmission $submission): array
    {
        $payload = $this->buildLearnerRecordPayload($submission);

        return DB::transaction(function () use ($submission, $payload) {
            $record = LearnerRecord::query()->create($payload);

            return ['record' => $record, 'created' => true];
        });
    }

    /**
     * @return array{record: LearnerRecord, created: bool, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function promoteAsUpdate(LearnerRecordSubmission $submission, LearnerRecord $target, bool $allowOverwrite = false): array
    {
        return DB::transaction(function () use ($submission, $target, $allowOverwrite) {
            $payload = $this->buildLearnerRecordPayload($submission);
            $before = $target->only(array_keys($payload));
            $merged = $this->mergePayload($target, $payload, $allowOverwrite);
            $target->fill($merged)->save();

            return [
                'record' => $target->fresh(),
                'created' => false,
                'before' => $before,
                'after' => $target->only(array_keys($payload)),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLearnerRecordPayload(LearnerRecordSubmission $submission): array
    {
        $sourceType = match ($submission->source_type) {
            LearnerRecordSubmissionSourceType::InstitutionPush,
            LearnerRecordSubmissionSourceType::InstitutionPull => LearnerRecordSourceType::InstitutionApi->value,
            LearnerRecordSubmissionSourceType::AdminImport => LearnerRecordSourceType::Import->value,
            LearnerRecordSubmissionSourceType::ManualEntry => LearnerRecordSourceType::Manual->value,
        };

        return [
            'awarding_institution_id' => $submission->source_institution_id,
            'import_id' => null,
            'institution_name_raw' => null,
            'student_id' => $submission->student_id,
            'certificate_no' => $submission->certificate_no,
            'nrc_number' => $submission->nrc_number,
            'passport_no' => $submission->passport_no,
            'first_name' => $submission->first_name,
            'last_name' => $submission->last_name,
            'other_names' => $submission->other_names,
            'gender' => $submission->gender,
            'program_of_study' => $submission->program_of_study,
            'qualification_title_normalized' => $submission->qualification_title_normalized,
            'year_awarded' => $submission->year_awarded,
            'award_date' => $submission->award_date,
            'classification' => $submission->classification,
            'source_type' => $sourceType,
            'source_reference' => $submission->source_reference,
            'raw_payload' => $this->safePayload($submission->payload_json),
            'nrc_normalized' => $submission->nrc_normalized,
            'passport_normalized' => $submission->passport_normalized,
            'name_normalized' => $submission->name_normalized,
            'student_id_normalized' => $submission->student_id_normalized,
            'certificate_no_normalized' => $submission->certificate_no_normalized,
            'dedupe_hash' => $submission->dedupe_hash,
            'is_active' => true,
            'verified_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergePayload(LearnerRecord $existing, array $payload, bool $allowOverwrite): array
    {
        if ($allowOverwrite) {
            return $payload;
        }

        $merged = $payload;
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $current = $existing->{$key} ?? null;
            if ($current !== null && $current !== '') {
                unset($merged[$key]);
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function safePayload(?array $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        unset($payload['awarding_institution_id'], $payload['authorization'], $payload['token']);

        return $payload;
    }
}
