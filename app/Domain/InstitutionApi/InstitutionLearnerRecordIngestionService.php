<?php

namespace App\Domain\InstitutionApi;

use App\Domain\LearnerRecords\LearnerRecordSubmissionIngestionService;
use App\Enums\LearnerRecordSubmissionSourceType;
use App\Models\InstitutionApiClient;
use App\Models\LearnerRecordSubmission;
use App\Models\LearnerRecordSubmissionBatch;

class InstitutionLearnerRecordIngestionService
{
    public function __construct(
        private readonly LearnerRecordSubmissionIngestionService $staging,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{submission: LearnerRecordSubmission, batch: LearnerRecordSubmissionBatch, validation_failed: bool}
     */
    public function stageOne(
        InstitutionApiClient $client,
        array $payload,
        ?LearnerRecordSubmissionBatch $batch = null,
        ?int $rowNumber = null,
    ): array {
        return $this->staging->ingestOne(
            sourceType: LearnerRecordSubmissionSourceType::InstitutionPush,
            sourceInstitutionId: (int) $client->awarding_institution_id,
            payload: $payload,
            institutionApiClientId: (int) $client->id,
            institutionApiBatchId: $batch?->institution_api_batch_id,
            rowNumber: $rowNumber,
            existingBatch: $batch,
        );
    }
}
