<?php

namespace App\Domain\Verification\Events;

use App\Models\Qualification;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;

class QualificationAssignedToLevel2Reviewer
{
    public function __construct(
        public readonly Qualification $qualification,
        public readonly User $assignedBy,
        public readonly User $assignedTo,
        public readonly ?VerificationAssignmentCategory $category = null,
    ) {
    }
}
