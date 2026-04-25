<?php

namespace App\Domain\Applications\Events;

use App\Models\Application;
use App\Models\User;

class ApplicationSubmitted
{
    public function __construct(
        public readonly Application $application,
        public readonly User $actor,
        public readonly bool $isResubmission,
    ) {
    }
}

