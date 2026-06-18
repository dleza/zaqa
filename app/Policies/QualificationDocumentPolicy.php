<?php

namespace App\Policies;

use App\Domain\Documents\QualificationDocumentEvidence;
use App\Models\QualificationDocument;
use App\Models\User;

class QualificationDocumentPolicy
{
    public function view(User $user, QualificationDocument $document): bool
    {
        if (! $document->application || (int) $document->application->applicant_user_id !== (int) $user->id) {
            return false;
        }

        return QualificationDocumentEvidence::isActiveEvidence($document);
    }

    public function download(User $user, QualificationDocument $document): bool
    {
        return $this->view($user, $document);
    }

    public function delete(User $user, QualificationDocument $document): bool
    {
        return $document->application
            && (int) $document->application->applicant_user_id === (int) $user->id
            && $user->can('update', $document->application)
            && QualificationDocumentEvidence::isActiveEvidence($document);
    }
}

