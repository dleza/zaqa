<?php

namespace App\Domain\LearnerRecords;

use App\Enums\LearnerRecordMatchStatus;

final class LearnerRecordMatchResult
{
    /**
     * @param  array<string, mixed>  $matchedFields
     * @param  list<int>  $candidateRecordIds
     */
    public function __construct(
        public readonly LearnerRecordMatchStatus $status,
        public readonly int $confidence,
        public readonly ?int $learnerRecordId,
        public readonly string $source,
        public readonly array $matchedFields = [],
        public readonly array $candidateRecordIds = [],
        public readonly ?string $failureReason = null,
    ) {
    }

    public function isMatchedAndSafe(int $threshold): bool
    {
        return $this->status === LearnerRecordMatchStatus::Matched
            && $this->learnerRecordId !== null
            && $this->confidence >= $threshold;
    }
}

