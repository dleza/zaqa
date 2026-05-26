<?php

namespace App\Domain\InstitutionIntegrations;

use App\Enums\InstitutionLearnerLookupStatus;

class InstitutionLearnerLookupResult
{
    /**
     * @param  array<string, mixed>|null  $learnerRecordPayload
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public readonly bool $found,
        public readonly InstitutionLearnerLookupStatus $status,
        public readonly ?array $learnerRecordPayload = null,
        public readonly ?int $confidenceHint = null,
        public readonly ?string $sourceReference = null,
        public readonly ?array $rawResponse = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $httpStatus = null,
    ) {
    }

    public function isFound(): bool
    {
        return $this->found && $this->status === InstitutionLearnerLookupStatus::Found;
    }
}

