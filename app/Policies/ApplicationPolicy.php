<?php

namespace App\Policies;

use App\Enums\ApplicationStatus;
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
        return $this->view($user, $application)
            && in_array($application->current_status, [ApplicationStatus::Draft, ApplicationStatus::SentBack], true);
    }

    public function submit(User $user, Application $application): bool
    {
        return $this->update($user, $application);
    }

    public function delete(User $user, Application $application): bool
    {
        return $this->view($user, $application)
            && $application->current_status === ApplicationStatus::Draft;
    }
}

