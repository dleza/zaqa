<?php

namespace App\Domain\Verification\Events;

use App\Models\Application;
use App\Models\User;

class ApplicationLevel1Completed
{
    public function __construct(
        public readonly Application $application,
        public readonly User $level1Actor,
        public readonly User $assignedBy,
        public readonly string $findings,
    ) {
    }
}

