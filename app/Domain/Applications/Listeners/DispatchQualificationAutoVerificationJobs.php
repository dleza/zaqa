<?php

namespace App\Domain\Applications\Listeners;

use App\Domain\Applications\Events\ApplicationSubmitted;
use App\Enums\VerificationState;
use App\Jobs\Verification\ProcessQualificationAutoVerificationJob;

class DispatchQualificationAutoVerificationJobs
{
    public function handle(ApplicationSubmitted $event): void
    {
        $application = $event->application->fresh(['qualifications']);
        if (! $application) {
            return;
        }

        foreach ($application->qualifications as $qualification) {
            if ($qualification->verification_state !== VerificationState::AwaitingAutoVerification) {
                continue;
            }

            ProcessQualificationAutoVerificationJob::dispatch((int) $qualification->id);
        }
    }
}

