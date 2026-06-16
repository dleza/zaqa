<?php

namespace App\Policies;

use App\Models\LearnerRecordSubmission;
use App\Models\User;

class LearnerRecordSubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('learner_record_submissions.view');
    }

    public function view(User $user, LearnerRecordSubmission $submission): bool
    {
        return $user->can('learner_record_submissions.view');
    }

    public function review(User $user, LearnerRecordSubmission $submission): bool
    {
        return $user->can('learner_record_submissions.review');
    }

    public function approve(User $user, LearnerRecordSubmission $submission): bool
    {
        return $user->can('learner_record_submissions.approve');
    }

    public function reject(User $user, LearnerRecordSubmission $submission): bool
    {
        return $user->can('learner_record_submissions.reject');
    }
}
