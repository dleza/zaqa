<?php

namespace App\Domain\Verification\Events;

use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;

class QualificationSentBackToApplicant
{
    public function __construct(
        public readonly Qualification $qualification,
        public readonly Application $application,
        public readonly User $actor,
        public readonly string $comment,
    ) {}
}
