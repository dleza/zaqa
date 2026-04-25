<?php

namespace App\Policies;

use App\Models\QualificationDocument;
use App\Models\User;

class QualificationDocumentPolicy
{
    public function view(User $user, QualificationDocument $document): bool
    {
        return $document->application
            && (int) $document->application->applicant_user_id === (int) $user->id;
    }

    public function download(User $user, QualificationDocument $document): bool
    {
        return $this->view($user, $document);
    }

    public function delete(User $user, QualificationDocument $document): bool
    {
        return $this->view($user, $document)
            && $user->can('update', $document->application);
    }
}

