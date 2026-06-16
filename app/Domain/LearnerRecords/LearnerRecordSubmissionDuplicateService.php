<?php

namespace App\Domain\LearnerRecords;

use App\Models\LearnerRecord;
use App\Models\LearnerRecordSubmission;
use App\Enums\LearnerRecordSubmissionStatus;

class LearnerRecordSubmissionDuplicateService
{
    /**
     * @return list<array{
     *     learner_record_id: int|null,
     *     submission_id: int|null,
     *     match_type: string,
     *     score: int,
     *     matched_fields: list<string>,
     *     summary: array<string, mixed>
     * }>
     */
    public function detect(
        int $sourceInstitutionId,
        ?string $dedupeHash,
        ?string $studentIdNorm,
        ?string $certificateNoNorm,
        ?string $nrcNorm,
        ?string $passportNorm,
        ?string $nameNorm,
        ?string $titleNorm,
        ?int $yearAwarded,
        ?int $excludeSubmissionId = null,
    ): array {
        $candidates = [];

        if ($dedupeHash) {
            $exact = LearnerRecord::query()
                ->where('dedupe_hash', $dedupeHash)
                ->where('is_active', true)
                ->first();

            if ($exact instanceof LearnerRecord) {
                $candidates[] = $this->candidateFromLearnerRecord($exact, 'exact_dedupe_hash', 100, ['dedupe_hash']);
            }

            $pendingExact = LearnerRecordSubmission::query()
                ->where('dedupe_hash', $dedupeHash)
                ->where('status', LearnerRecordSubmissionStatus::Pending->value)
                ->when($excludeSubmissionId, fn ($q) => $q->where('id', '!=', $excludeSubmissionId))
                ->first();

            if ($pendingExact instanceof LearnerRecordSubmission) {
                $candidates[] = $this->candidateFromSubmission($pendingExact, 'pending_dedupe_hash', 95, ['dedupe_hash']);
            }
        }

        $recordIds = $this->candidateRecordIds(
            institutionId: $sourceInstitutionId,
            yearAwarded: $yearAwarded,
            studentIdNorm: $studentIdNorm,
            certificateNoNorm: $certificateNoNorm,
            nrcNorm: $nrcNorm,
            passportNorm: $passportNorm,
        );

        foreach (LearnerRecord::query()->whereIn('id', $recordIds)->where('is_active', true)->get() as $record) {
            if ($dedupeHash && $record->dedupe_hash === $dedupeHash) {
                continue;
            }

            $matched = [];
            $score = 0;

            if ((int) ($record->awarding_institution_id ?? 0) === $sourceInstitutionId) {
                $matched[] = 'institution';
                $score += 20;
            }

            if ($yearAwarded && (int) ($record->year_awarded ?? 0) === $yearAwarded) {
                $matched[] = 'year_awarded';
                $score += 15;
            }

            if ($studentIdNorm && $record->student_id_normalized === $studentIdNorm) {
                $matched[] = 'student_id';
                $score += 25;
            }

            if ($certificateNoNorm && $record->certificate_no_normalized === $certificateNoNorm) {
                $matched[] = 'certificate_no';
                $score += 25;
            }

            if ($nrcNorm && $record->nrc_normalized === $nrcNorm) {
                $matched[] = 'nrc_number';
                $score += 25;
            }

            if ($passportNorm && $record->passport_normalized === $passportNorm) {
                $matched[] = 'passport_no';
                $score += 25;
            }

            if ($nameNorm && $record->name_normalized === $nameNorm) {
                $matched[] = 'name';
                $score += 15;
            }

            if ($titleNorm && $record->qualification_title_normalized === $titleNorm) {
                $matched[] = 'program_of_study';
                $score += 10;
            }

            if ($score >= 40) {
                $candidates[] = $this->candidateFromLearnerRecord($record, 'fuzzy', min(99, $score), $matched);
            }
        }

        usort($candidates, fn (array $a, array $b) => ($b['score'] <=> $a['score']) ?: (($a['learner_record_id'] ?? 0) <=> ($b['learner_record_id'] ?? 0)));

        $unique = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $key = ($candidate['learner_record_id'] ?? 's:'.$candidate['submission_id']) . ':'.$candidate['match_type'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $candidate;
            if (count($unique) >= 10) {
                break;
            }
        }

        return $unique;
    }

    /**
     * @return list<int>
     */
    private function candidateRecordIds(
        int $institutionId,
        ?int $yearAwarded,
        ?string $studentIdNorm,
        ?string $certificateNoNorm,
        ?string $nrcNorm,
        ?string $passportNorm,
    ): array {
        $ids = [];

        if ($certificateNoNorm) {
            $ids = array_merge($ids, LearnerRecord::query()
                ->where('is_active', true)
                ->where('certificate_no_normalized', $certificateNoNorm)
                ->where('awarding_institution_id', $institutionId)
                ->when($yearAwarded, fn ($q) => $q->where('year_awarded', $yearAwarded))
                ->limit(10)
                ->pluck('id')
                ->all());
        }

        if ($studentIdNorm) {
            $ids = array_merge($ids, LearnerRecord::query()
                ->where('is_active', true)
                ->where('student_id_normalized', $studentIdNorm)
                ->where('awarding_institution_id', $institutionId)
                ->when($yearAwarded, fn ($q) => $q->where('year_awarded', $yearAwarded))
                ->limit(10)
                ->pluck('id')
                ->all());
        }

        if ($nrcNorm) {
            $ids = array_merge($ids, LearnerRecord::query()
                ->where('is_active', true)
                ->where('nrc_normalized', $nrcNorm)
                ->limit(10)
                ->pluck('id')
                ->all());
        }

        if ($passportNorm) {
            $ids = array_merge($ids, LearnerRecord::query()
                ->where('is_active', true)
                ->where('passport_normalized', $passportNorm)
                ->limit(10)
                ->pluck('id')
                ->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @param  list<string>  $matchedFields
     * @return array{learner_record_id: int, submission_id: null, match_type: string, score: int, matched_fields: list<string>, summary: array<string, mixed>}
     */
    private function candidateFromLearnerRecord(LearnerRecord $record, string $matchType, int $score, array $matchedFields): array
    {
        return [
            'learner_record_id' => (int) $record->id,
            'submission_id' => null,
            'match_type' => $matchType,
            'score' => $score,
            'matched_fields' => $matchedFields,
            'summary' => [
                'student_id' => $record->student_id,
                'certificate_no' => $record->certificate_no,
                'first_name' => $record->first_name,
                'last_name' => $record->last_name,
                'program_of_study' => $record->program_of_study,
                'year_awarded' => $record->year_awarded,
            ],
        ];
    }

    /**
     * @param  list<string>  $matchedFields
     * @return array{learner_record_id: null, submission_id: int, match_type: string, score: int, matched_fields: list<string>, summary: array<string, mixed>}
     */
    private function candidateFromSubmission(LearnerRecordSubmission $submission, string $matchType, int $score, array $matchedFields): array
    {
        return [
            'learner_record_id' => null,
            'submission_id' => (int) $submission->id,
            'match_type' => $matchType,
            'score' => $score,
            'matched_fields' => $matchedFields,
            'summary' => [
                'student_id' => $submission->student_id,
                'certificate_no' => $submission->certificate_no,
                'first_name' => $submission->first_name,
                'last_name' => $submission->last_name,
                'program_of_study' => $submission->program_of_study,
                'year_awarded' => $submission->year_awarded,
            ],
        ];
    }
}
