<?php

namespace App\Domain\LearnerRecords;

use App\Domain\Audit\AuditLogService;
use App\Enums\LearnerRecordSubmissionBatchStatus;
use App\Enums\LearnerRecordSubmissionSourceType;
use App\Enums\LearnerRecordSubmissionStatus;
use App\Models\LearnerRecordSubmission;
use App\Models\LearnerRecordSubmissionBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LearnerRecordSubmissionIngestionService
{
    public function __construct(
        private readonly LearnerRecordSubmissionDuplicateService $duplicates,
        private readonly LearnerRecordSubmissionBatchReferenceGenerator $references,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{submission: LearnerRecordSubmission, batch: LearnerRecordSubmissionBatch, validation_failed: bool}
     */
    public function ingestOne(
        LearnerRecordSubmissionSourceType $sourceType,
        int $sourceInstitutionId,
        array $payload,
        ?int $institutionApiClientId = null,
        ?int $institutionApiBatchId = null,
        ?int $sourceIntegrationId = null,
        ?string $sourceReference = null,
        ?string $externalRecordId = null,
        ?int $rowNumber = null,
        ?LearnerRecordSubmissionBatch $existingBatch = null,
    ): array {
        $batch = $existingBatch ?? $this->createBatch(
            sourceType: $sourceType,
            sourceInstitutionId: $sourceInstitutionId,
            institutionApiClientId: $institutionApiClientId,
            institutionApiBatchId: $institutionApiBatchId,
            totalRecords: 1,
        );

        $validation = $this->validatePayload($payload);
        if ($validation !== []) {
            $submission = $this->createFailedSubmission(
                batch: $batch,
                sourceType: $sourceType,
                sourceInstitutionId: $sourceInstitutionId,
                payload: $payload,
                validationErrors: $validation,
                institutionApiClientId: $institutionApiClientId,
                institutionApiBatchId: $institutionApiBatchId,
                sourceIntegrationId: $sourceIntegrationId,
                sourceReference: $sourceReference,
                externalRecordId: $externalRecordId,
                rowNumber: $rowNumber,
            );

            $this->incrementBatchCounters($batch->id, failedValidation: 1);

            return ['submission' => $submission, 'batch' => $batch->fresh(), 'validation_failed' => true];
        }

        $normalized = $this->normalizePayload($sourceInstitutionId, $payload, $sourceReference);
        $duplicateCandidates = $this->duplicates->detect(
            sourceInstitutionId: $sourceInstitutionId,
            dedupeHash: $normalized['dedupe_hash'],
            studentIdNorm: $normalized['student_id_normalized'],
            certificateNoNorm: $normalized['certificate_no_normalized'],
            nrcNorm: $normalized['nrc_normalized'],
            passportNorm: $normalized['passport_normalized'],
            nameNorm: $normalized['name_normalized'],
            titleNorm: $normalized['qualification_title_normalized'],
            yearAwarded: $normalized['year_awarded'],
        );

        $riskFlags = $this->buildRiskFlags($normalized, $duplicateCandidates);

        $submission = LearnerRecordSubmission::query()->create(array_merge($normalized, [
            'batch_id' => $batch->id,
            'source_type' => $sourceType,
            'source_institution_id' => $sourceInstitutionId,
            'source_integration_id' => $sourceIntegrationId,
            'institution_api_client_id' => $institutionApiClientId,
            'institution_api_batch_id' => $institutionApiBatchId,
            'source_reference' => $sourceReference ?? $normalized['source_reference'] ?? null,
            'external_record_id' => $externalRecordId,
            'row_number' => $rowNumber,
            'payload_json' => $this->safePayload($payload),
            'status' => LearnerRecordSubmissionStatus::Pending,
            'duplicate_candidates' => $duplicateCandidates !== [] ? $duplicateCandidates : null,
            'risk_flags' => $riskFlags !== [] ? $riskFlags : null,
            'received_at' => now(),
        ]));

        $this->incrementBatchCounters($batch->id, pending: 1);

        $this->audit->record(
            eventType: 'learner_record_submission.received',
            module: 'LearnerRecords',
            actionName: 'learner_record_submission_received',
            message: 'External learner record received and staged for review.',
            entityType: LearnerRecordSubmission::class,
            entityId: (int) $submission->id,
            metadata: [
                'submission_id' => (int) $submission->id,
                'batch_id' => (int) $batch->id,
                'batch_reference' => $batch->reference,
                'source_type' => $sourceType->value,
                'source_institution_id' => $sourceInstitutionId,
                'duplicate_candidate_count' => count($duplicateCandidates),
                'student_id_masked' => $this->maskIdentifier($submission->student_id),
                'certificate_no_masked' => $this->maskIdentifier($submission->certificate_no),
            ],
        );

        return ['submission' => $submission, 'batch' => $batch->fresh(), 'validation_failed' => false];
    }

    public function createBatch(
        LearnerRecordSubmissionSourceType $sourceType,
        int $sourceInstitutionId,
        ?int $institutionApiClientId = null,
        ?int $institutionApiBatchId = null,
        ?int $uploadedByUserId = null,
        int $totalRecords = 0,
    ): LearnerRecordSubmissionBatch {
        return LearnerRecordSubmissionBatch::query()->create([
            'reference' => $this->references->generate(),
            'source_type' => $sourceType,
            'source_institution_id' => $sourceInstitutionId,
            'institution_api_client_id' => $institutionApiClientId,
            'institution_api_batch_id' => $institutionApiBatchId,
            'uploaded_by_user_id' => $uploadedByUserId,
            'status' => LearnerRecordSubmissionBatchStatus::Received,
            'total_records' => $totalRecords,
            'received_at' => now(),
        ]);
    }

    public function finalizeBatch(int $batchId): void
    {
        DB::transaction(function () use ($batchId) {
            $batch = LearnerRecordSubmissionBatch::query()->lockForUpdate()->findOrFail($batchId);

            $total = LearnerRecordSubmission::query()->where('batch_id', $batchId)->count();
            $pending = LearnerRecordSubmission::query()
                ->where('batch_id', $batchId)
                ->where('status', LearnerRecordSubmissionStatus::Pending->value)
                ->count();

            $batch->forceFill([
                'status' => LearnerRecordSubmissionBatchStatus::PendingReview,
                'total_records' => $total,
                'pending_count' => $pending,
                'completed_at' => now(),
                'summary_message' => 'Records received and pending ZAQA review.',
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    public function validatePayload(array $payload): array
    {
        $validator = \Illuminate\Support\Facades\Validator::make($payload, [
            'student_id' => ['nullable', 'string', 'max:100', 'required_without_all:certificate_no,nrc_number,passport_no'],
            'certificate_no' => ['nullable', 'string', 'max:100', 'required_without_all:student_id,nrc_number,passport_no'],
            'nrc_number' => ['nullable', 'string', 'max:50', 'required_without_all:student_id,certificate_no,passport_no'],
            'passport_no' => ['nullable', 'string', 'max:50', 'required_without_all:student_id,certificate_no,nrc_number'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:150'],
            'gender' => ['nullable', 'string', 'max:20'],
            'program_of_study' => ['required', 'string', 'max:255'],
            'year_awarded' => ['required', 'integer', 'min:1900', 'max:2100'],
            'award_date' => ['nullable', 'date'],
            'source_reference' => ['nullable', 'string', 'max:255'],
        ]);

        return $validator->fails() ? $validator->errors()->toArray() : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(int $sourceInstitutionId, array $payload, ?string $sourceReference): array
    {
        $studentId = $this->stringOrNull($payload['student_id'] ?? null);
        $certificateNo = $this->stringOrNull($payload['certificate_no'] ?? null);
        $nrc = $this->stringOrNull($payload['nrc_number'] ?? null);
        $passport = $this->stringOrNull($payload['passport_no'] ?? null);
        $firstName = $this->stringOrNull($payload['first_name'] ?? null);
        $lastName = $this->stringOrNull($payload['last_name'] ?? null);
        $otherNames = $this->stringOrNull($payload['other_names'] ?? null);
        $gender = $this->stringOrNull($payload['gender'] ?? null);
        $program = $this->stringOrNull($payload['program_of_study'] ?? null);
        $yearAwarded = isset($payload['year_awarded']) ? (int) $payload['year_awarded'] : null;
        $awardDate = $this->stringOrNull($payload['award_date'] ?? null);
        $classification = $this->stringOrNull($payload['classification'] ?? null);
        $examinationNumber = $this->stringOrNull($payload['examination_number'] ?? null);
        $resolvedSourceReference = $sourceReference ?? $this->stringOrNull($payload['source_reference'] ?? null);

        $studentIdNorm = \App\Support\Normalization\LearnerRecordNormalizer::normalizeStudentId($studentId);
        $certNorm = \App\Support\Normalization\LearnerRecordNormalizer::normalizeCertificateNo($certificateNo);
        $nrcNorm = \App\Support\Normalization\LearnerRecordNormalizer::normalizeNrc($nrc);
        $passportNorm = \App\Support\Normalization\LearnerRecordNormalizer::normalizePassport($passport);
        $nameNorm = \App\Support\Normalization\LearnerRecordNormalizer::normalizeNameParts($firstName, $otherNames, $lastName);
        $titleNorm = \App\Support\Normalization\LearnerRecordNormalizer::normalizeProgramTitle($program);

        $hash = \App\Support\Normalization\LearnerRecordNormalizer::dedupeHash(
            awardingInstitutionId: $sourceInstitutionId,
            certificateNoNormalized: $certNorm,
            studentIdNormalized: $studentIdNorm,
            yearAwarded: $yearAwarded,
        );

        return [
            'student_id' => $studentId,
            'certificate_no' => $certificateNo,
            'nrc_number' => $nrc,
            'passport_no' => $passport,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'other_names' => $otherNames,
            'gender' => $gender,
            'program_of_study' => $program,
            'year_awarded' => $yearAwarded,
            'award_date' => $awardDate,
            'classification' => $classification,
            'examination_number' => $examinationNumber,
            'source_reference' => $resolvedSourceReference,
            'nrc_normalized' => $nrcNorm,
            'passport_normalized' => $passportNorm,
            'name_normalized' => $nameNorm,
            'student_id_normalized' => $studentIdNorm,
            'certificate_no_normalized' => $certNorm,
            'qualification_title_normalized' => $titleNorm,
            'dedupe_hash' => $hash,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<string>>  $validationErrors
     */
    private function createFailedSubmission(
        LearnerRecordSubmissionBatch $batch,
        LearnerRecordSubmissionSourceType $sourceType,
        int $sourceInstitutionId,
        array $payload,
        array $validationErrors,
        ?int $institutionApiClientId,
        ?int $institutionApiBatchId,
        ?int $sourceIntegrationId,
        ?string $sourceReference,
        ?string $externalRecordId,
        ?int $rowNumber,
    ): LearnerRecordSubmission {
        return LearnerRecordSubmission::query()->create([
            'batch_id' => $batch->id,
            'source_type' => $sourceType,
            'source_institution_id' => $sourceInstitutionId,
            'source_integration_id' => $sourceIntegrationId,
            'institution_api_client_id' => $institutionApiClientId,
            'institution_api_batch_id' => $institutionApiBatchId,
            'source_reference' => $sourceReference,
            'external_record_id' => $externalRecordId,
            'row_number' => $rowNumber,
            'payload_json' => $this->safePayload($payload),
            'status' => LearnerRecordSubmissionStatus::Rejected,
            'validation_errors' => $validationErrors,
            'review_notes' => 'Failed structural validation at ingestion.',
            'received_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $duplicateCandidates
     * @return list<string>
     */
    private function buildRiskFlags(array $normalized, array $duplicateCandidates): array
    {
        $flags = [];

        if (! $normalized['nrc_normalized'] && ! $normalized['passport_normalized']) {
            $flags[] = 'missing_nrc_passport';
        }

        if ($duplicateCandidates !== []) {
            $flags[] = 'possible_duplicate';
        }

        foreach ($duplicateCandidates as $candidate) {
            if (($candidate['match_type'] ?? '') === 'exact_dedupe_hash') {
                $flags[] = 'exact_duplicate_hash';
                break;
            }
        }

        return $flags;
    }

    private function incrementBatchCounters(int $batchId, int $pending = 0, int $failedValidation = 0): void
    {
        DB::transaction(function () use ($batchId, $pending, $failedValidation) {
            $batch = LearnerRecordSubmissionBatch::query()->lockForUpdate()->findOrFail($batchId);
            $batch->forceFill([
                'pending_count' => (int) $batch->pending_count + $pending,
                'failed_validation_count' => (int) $batch->failed_validation_count + $failedValidation,
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safePayload(array $payload): array
    {
        unset($payload['awarding_institution_id'], $payload['authorization'], $payload['token']);

        return $payload;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));

        return $s === '' ? null : $s;
    }

    private function maskIdentifier(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $len = strlen($value);

        return $len <= 4 ? '****' : str_repeat('*', $len - 4).substr($value, -4);
    }
}
