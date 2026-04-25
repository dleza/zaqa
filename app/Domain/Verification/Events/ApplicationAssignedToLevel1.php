<?php

namespace App\Domain\Verification\Events;

use App\Models\Application;
use App\Models\User;

class ApplicationAssignedToLevel1
{
    public function __construct(
        public readonly Application $application,
        public readonly User $assignedBy,
        public readonly User $assignedTo,
        public readonly ?string $comment = null,
    ) {
    }
}

