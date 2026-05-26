<?php

namespace App\Domain\Verification;

class AutoAssignmentResult
{
    public function __construct(
        public readonly bool $assigned,
        public readonly ?int $categoryId = null,
        public readonly ?int $assigneeUserId = null,
        public readonly ?string $failureReason = null,
        public readonly bool $alreadyAssigned = false,
    ) {
    }
}

