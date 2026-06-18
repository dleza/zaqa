<?php

namespace App\Domain\Verification;

use App\Models\Application;
use App\Models\QualificationDocument;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Level 1 and Level 2 officers may view applicant uploads but must not replace or remove them.
 * Super Admin is exempt.
 */
final class VerificationApplicantDocumentGuard
{
    public static function isApplicantUploaded(QualificationDocument $document, Application $application): bool
    {
        $uploaderId = (int) ($document->uploaded_by_user_id ?? 0);
        $applicantId = (int) ($application->applicant_user_id ?? 0);

        return $uploaderId > 0 && $applicantId > 0 && $uploaderId === $applicantId;
    }

    public static function isRestrictedVerificationOfficer(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole('Super Admin')) {
            return false;
        }

        return $user->can('verification.level1.process') || $user->can('verification.level2.review');
    }

    public static function officerBlockedFromModifyingApplicantDocument(
        ?User $user,
        QualificationDocument $document,
        Application $application,
    ): bool {
        if (! self::isApplicantUploaded($document, $application)) {
            return false;
        }

        return self::isRestrictedVerificationOfficer($user);
    }

    public static function assertOfficerMayModifyDocument(
        ?User $user,
        QualificationDocument $document,
        Application $application,
    ): void {
        if (! self::officerBlockedFromModifyingApplicantDocument($user, $document, $application)) {
            return;
        }

        throw ValidationException::withMessages([
            'file' => 'Documents uploaded by the applicant cannot be replaced or removed by Level 1 or Level 2 officers.',
        ]);
    }
}
