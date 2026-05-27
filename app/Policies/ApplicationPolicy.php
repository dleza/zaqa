<?php

namespace App\Policies;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    public function view(User $user, Application $application): bool
    {
        return $application->applicant_user_id === $user->id;
    }

    public function update(User $user, Application $application): bool
    {
        if (! $this->view($user, $application)) {
            return false;
        }

        if ($application->hasPendingFinanceProofReview()) {
            return false;
        }

        if (in_array($application->current_status, [ApplicationStatus::Draft, ApplicationStatus::PendingPayment, ApplicationStatus::SentBack], true)) {
            return true;
        }

        return $application->qualifications()
            ->where('verification_state', VerificationState::ReturnedToApplicant->value)
            ->exists();
    }

    public function submit(User $user, Application $application): bool
    {
        return $this->update($user, $application);
    }

    public function delete(User $user, Application $application): bool
    {
        return $this->view($user, $application)
            && ! $application->hasPendingFinanceProofReview()
            && $application->current_status === ApplicationStatus::Draft;
    }
}
