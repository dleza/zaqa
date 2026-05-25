<?php

namespace App\Domain\LearnerRecords;

use App\Enums\LearnerRecordMatchStatus;
use App\Models\LearnerRecord;
use App\Models\Qualification;
use App\Support\Normalization\LearnerRecordNormalizer;
use Illuminate\Support\Collection;

class LearnerRecordMatchingService
{
    private const WEIGHT_INSTITUTION = 20;
    private const WEIGHT_YEAR = 15;
    private const WEIGHT_STUDENT_ID = 25;
    private const WEIGHT_CERTIFICATE_NO = 25;
    private const WEIGHT_NRC_PASSPORT = 25;
    private const WEIGHT_NAME = 15;
    private const WEIGHT_PROGRAM_TITLE = 10;

    private const AMBIGUITY_MARGIN = 5;
    private const MAX_CANDIDATES = 50;

    public function match(Qualification $qualification): LearnerRecordMatchResult
    {
        $qualification->loadMissing('application');

        $threshold = (int) config('auto_verification.threshold', 70);

        $institutionId = $qualification->awarding_institution_id ? (int) $qualification->awarding_institution_id : null;
        $yearAwarded = $qualification->award_date ? (int) $qualification->award_date->format('Y') : null;

        $studentNorm = LearnerRecordNormalizer::normalizeStudentId((string) ($qualification->student_number ?? '')) ?: null;
        $certNorm = LearnerRecordNormalizer::normalizeCertificateNo((string) ($qualification->certificate_number ?? '')) ?: null;

        $holderId = trim((string) ($qualification->nrc_passport_number ?? ''));
        $nrcNorm = LearnerRecordNormalizer::normalizeNrc($holderId);
        $passportNorm = LearnerRecordNormalizer::normalizePassport($holderId);

        $nameNorm = LearnerRecordNormalizer::normalizeFullName((string) ($qualification->qualification_holder_name ?? ''));
        $titleNorm = LearnerRecordNormalizer::normalizeProgramTitle((string) ($qualification->title_of_qualification ?? ''));

        $candidateIds = $this->candidateIds(
            institutionId: $institutionId,
            yearAwarded: $yearAwarded,
            studentIdNorm: $studentNorm,
            certificateNoNorm: $certNorm,
            nrcNorm: $nrcNorm,
            passportNorm: $passportNorm,
        );

        if ($candidateIds === []) {
            return new LearnerRecordMatchResult(
                status: LearnerRecordMatchStatus::NotFound,
                confidence: 0,
                learnerRecordId: null,
                source: 'internal',
                matchedFields: [],
                candidateRecordIds: [],
                failureReason: 'no_candidates',
            );
        }

        /** @var Collection<int, LearnerRecord> $candidates */
        $candidates = LearnerRecord::query()
            ->whereIn('id', $candidateIds)
            ->where('is_active', true)
            ->get();

        $scored = [];
        foreach ($candidates as $candidate) {
            if ($institutionId && $candidate->awarding_institution_id && (int) $candidate->awarding_institution_id !== $institutionId) {
                continue;
            }

            $matchedFields = [];
            $score = 0;

            if ($institutionId && (int) ($candidate->awarding_institution_id ?? 0) === $institutionId) {
                $score += self::WEIGHT_INSTITUTION;
                $matchedFields['awarding_institution_id'] = true;
            }

            if ($yearAwarded && (int) ($candidate->year_awarded ?? 0) === $yearAwarded) {
                $score += self::WEIGHT_YEAR;
                $matchedFields['year_awarded'] = true;
            }

            if ($studentNorm && $candidate->student_id_normalized && $candidate->student_id_normalized === $studentNorm) {
                $score += self::WEIGHT_STUDENT_ID;
                $matchedFields['student_id'] = true;
            }

            if ($certNorm && $candidate->certificate_no_normalized && $candidate->certificate_no_normalized === $certNorm) {
                $score += self::WEIGHT_CERTIFICATE_NO;
                $matchedFields['certificate_no'] = true;
            }

            $idMatched = false;
            if ($nrcNorm && $candidate->nrc_normalized && $candidate->nrc_normalized === $nrcNorm) {
                $idMatched = true;
                $matchedFields['nrc_number'] = true;
            }
            if (! $idMatched && $passportNorm && $candidate->passport_normalized && $candidate->passport_normalized === $passportNorm) {
                $idMatched = true;
                $matchedFields['passport_no'] = true;
            }
            if ($idMatched) {
                $score += self::WEIGHT_NRC_PASSPORT;
            }

            if ($nameNorm && $candidate->name_normalized && $candidate->name_normalized === $nameNorm) {
                $score += self::WEIGHT_NAME;
                $matchedFields['name'] = true;
            }

            if ($titleNorm && $candidate->qualification_title_normalized && $candidate->qualification_title_normalized === $titleNorm) {
                $score += self::WEIGHT_PROGRAM_TITLE;
                $matchedFields['program_of_study'] = true;
            }

            $hasStrongEvidence = (bool) ($matchedFields['student_id'] ?? false)
                || (bool) ($matchedFields['certificate_no'] ?? false)
                || (bool) ($matchedFields['nrc_number'] ?? false)
                || (bool) ($matchedFields['passport_no'] ?? false);

            if (! $hasStrongEvidence) {
                // Safety rule: do not match only by title/institution/year/name.
                continue;
            }

            $score = min(100, $score);

            $scored[] = [
                'id' => (int) $candidate->id,
                'score' => $score,
                'matched_fields' => $matchedFields,
            ];
        }

        if ($scored === []) {
            return new LearnerRecordMatchResult(
                status: LearnerRecordMatchStatus::NotFound,
                confidence: 0,
                learnerRecordId: null,
                source: 'internal',
                matchedFields: [],
                candidateRecordIds: array_values($candidateIds),
                failureReason: 'no_safe_candidates',
            );
        }

        usort($scored, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['id'] <=> $b['id']));

        $best = $scored[0];
        $bestScore = (int) $best['score'];
        $bestId = (int) $best['id'];

        $aboveThreshold = array_values(array_filter($scored, fn ($r) => (int) $r['score'] >= $threshold));
        $candidateAboveIds = array_values(array_map(fn ($r) => (int) $r['id'], $aboveThreshold));

        if (count($candidateAboveIds) > 1) {
            $second = $aboveThreshold[1] ?? null;
            if ($second && ($bestScore - (int) $second['score']) <= self::AMBIGUITY_MARGIN) {
                return new LearnerRecordMatchResult(
                    status: LearnerRecordMatchStatus::Ambiguous,
                    confidence: $bestScore,
                    learnerRecordId: null,
                    source: 'internal',
                    matchedFields: (array) ($best['matched_fields'] ?? []),
                    candidateRecordIds: $candidateAboveIds,
                    failureReason: 'ambiguous_top_candidates',
                );
            }
        }

        if ($bestScore >= $threshold) {
            return new LearnerRecordMatchResult(
                status: LearnerRecordMatchStatus::Matched,
                confidence: $bestScore,
                learnerRecordId: $bestId,
                source: 'internal',
                matchedFields: (array) ($best['matched_fields'] ?? []),
                candidateRecordIds: $candidateAboveIds !== [] ? $candidateAboveIds : [$bestId],
                failureReason: null,
            );
        }

        $possibleIds = array_values(array_map(fn ($r) => (int) $r['id'], array_slice($scored, 0, 10)));

        return new LearnerRecordMatchResult(
            status: LearnerRecordMatchStatus::PossibleMatch,
            confidence: $bestScore,
            learnerRecordId: null,
            source: 'internal',
            matchedFields: (array) ($best['matched_fields'] ?? []),
            candidateRecordIds: $possibleIds,
            failureReason: 'below_threshold',
        );
    }

    /**
     * @return list<int>
     */
    private function candidateIds(
        ?int $institutionId,
        ?int $yearAwarded,
        ?string $studentIdNorm,
        ?string $certificateNoNorm,
        ?string $nrcNorm,
        ?string $passportNorm,
    ): array {
        $ids = [];

        if ($certificateNoNorm) {
            $query = LearnerRecord::query()
                ->where('is_active', true)
                ->where('certificate_no_normalized', $certificateNoNorm)
                ->when($institutionId, fn ($q) => $q->where('awarding_institution_id', $institutionId))
                ->when($yearAwarded, fn ($q) => $q->where('year_awarded', $yearAwarded))
                ->limit(25)
                ->pluck('id')
                ->all();
            $ids = array_merge($ids, array_map('intval', $query));
        }

        if ($studentIdNorm) {
            $query = LearnerRecord::query()
                ->where('is_active', true)
                ->where('student_id_normalized', $studentIdNorm)
                ->when($institutionId, fn ($q) => $q->where('awarding_institution_id', $institutionId))
                ->when($yearAwarded, fn ($q) => $q->where('year_awarded', $yearAwarded))
                ->limit(25)
                ->pluck('id')
                ->all();
            $ids = array_merge($ids, array_map('intval', $query));
        }

        if ($nrcNorm) {
            $query = LearnerRecord::query()
                ->where('is_active', true)
                ->where('nrc_normalized', $nrcNorm)
                ->limit(25)
                ->pluck('id')
                ->all();
            $ids = array_merge($ids, array_map('intval', $query));
        }

        if ($passportNorm) {
            $query = LearnerRecord::query()
                ->where('is_active', true)
                ->where('passport_normalized', $passportNorm)
                ->limit(25)
                ->pluck('id')
                ->all();
            $ids = array_merge($ids, array_map('intval', $query));
        }

        $ids = array_values(array_unique(array_filter($ids, fn ($id) => (int) $id > 0)));

        if (count($ids) > self::MAX_CANDIDATES) {
            $ids = array_slice($ids, 0, self::MAX_CANDIDATES);
        }

        return $ids;
    }
}
