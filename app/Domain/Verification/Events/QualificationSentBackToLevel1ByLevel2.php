<?php

namespace App\Domain\Verification\Events;

use App\Models\Qualification;
use App\Models\User;

class QualificationSentBackToLevel1ByLevel2
{
    public function __construct(
        public readonly Qualification $qualification,
        public readonly User $sentBy,
        public readonly string $comment,
        public readonly ?User $assignedToLevel1 = null,
    ) {
    }
}
