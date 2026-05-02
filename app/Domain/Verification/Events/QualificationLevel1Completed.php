<?php

namespace App\Domain\Verification\Events;

use App\Models\Qualification;
use App\Models\User;

class QualificationLevel1Completed
{
    public function __construct(
        public readonly Qualification $qualification,
        public readonly User $level1Actor,
        public readonly User $assignedBy,
        public readonly string $findings,
    ) {}
}
