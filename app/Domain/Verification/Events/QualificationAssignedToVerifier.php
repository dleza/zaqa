<?php

namespace App\Domain\Verification\Events;

use App\Models\Qualification;
use App\Models\User;

class QualificationAssignedToVerifier
{
    public function __construct(
        public readonly Qualification $qualification,
        public readonly User $assignedBy,
        public readonly User $assignedTo,
        public readonly ?string $comment = null,
        public readonly ?User $previousAssignee = null,
    ) {
    }
}
