<?php

namespace App\Domain\Verification\Events;

use App\Models\Application;
use App\Models\User;

class ApplicationSentBackToApplicant
{
    public function __construct(
        public readonly Application $application,
        public readonly User $actor,
        public readonly string $comment,
    ) {
    }
}

