<?php

namespace App\Domain\Verification\Events;

use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QualificationCorrectionsSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Qualification $qualification,
        public readonly Application $application,
        public readonly User $applicant,
        public readonly ?User $returnedToOfficer,
        public readonly bool $officerUnavailableFallback,
    ) {}
}
