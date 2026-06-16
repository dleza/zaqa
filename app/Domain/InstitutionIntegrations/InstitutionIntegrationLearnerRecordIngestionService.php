<?php

namespace App\Domain\InstitutionIntegrations;

use App\Domain\LearnerRecords\LearnerRecordSubmissionIngestionService;
use App\Enums\LearnerRecordSubmissionSourceType;
use App\Models\LearnerRecordSubmission;

class InstitutionIntegrationLearnerRecordIngestionService
{
    public function __construct(
        private readonly LearnerRecordSubmissionIngestionService $staging,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{submission: LearnerRecordSubmission, validation_failed: bool}
     */
    public function stageFromLookup(
        int $awardingInstitutionId,
        array $payload,
        ?string $sourceReference = null,
        ?int $sourceIntegrationId = null,
    ): array {
        $result = $this->staging->ingestOne(
            sourceType: LearnerRecordSubmissionSourceType::InstitutionPull,
            sourceInstitutionId: $awardingInstitutionId,
            payload: $payload,
            sourceIntegrationId: $sourceIntegrationId,
            sourceReference: $sourceReference,
        );

        return [
            'submission' => $result['submission'],
            'validation_failed' => $result['validation_failed'],
        ];
    }
}
