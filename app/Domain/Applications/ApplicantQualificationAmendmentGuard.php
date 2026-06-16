<?php

namespace App\Domain\Applications;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Restricts applicant edits during per-qualification send-back correction on paid/submitted applications.
 */
final class ApplicantQualificationAmendmentGuard
{
    public static function isRestrictedAmendmentMode(Application $application): bool
    {
        if (in_array($application->current_status, [
            ApplicationStatus::Draft,
            ApplicationStatus::PendingPayment,
            ApplicationStatus::SentBack,
        ], true)) {
            return false;
        }

        return $application->qualifications()
            ->where('verification_state', VerificationState::ReturnedToApplicant->value)
            ->exists();
    }

    public static function returnedQualificationsCount(Application $application): int
    {
        return (int) $application->qualifications()
            ->where('verification_state', VerificationState::ReturnedToApplicant->value)
            ->count();
    }

    public static function assertCanCreateQualification(Application $application): void
    {
        if (! self::isRestrictedAmendmentMode($application)) {
            return;
        }

        throw ValidationException::withMessages([
            'qualification' => 'You can only update qualifications that ZAQA has returned for correction.',
        ]);
    }

    public static function assertQualificationEditable(Application $application, ?Qualification $qualification): void
    {
        if (! self::isRestrictedAmendmentMode($application)) {
            return;
        }

        if (! $qualification) {
            throw ValidationException::withMessages([
                'qualification' => 'Select a qualification that ZAQA has returned for correction.',
            ]);
        }

        if ((int) $qualification->application_id !== (int) $application->id) {
            abort(404);
        }

        if ($qualification->verification_state !== VerificationState::ReturnedToApplicant) {
            throw ValidationException::withMessages([
                'qualification' => 'This qualification is not open for correction. Only items returned by ZAQA can be edited.',
            ]);
        }
    }

    public static function assertDocumentEditable(Application $application, ?Qualification $qualification): void
    {
        if (! self::isRestrictedAmendmentMode($application)) {
            return;
        }

        self::assertQualificationEditable($application, $qualification);
    }

    public static function assertDocumentDeletable(Application $application, QualificationDocument $document): void
    {
        if (! self::isRestrictedAmendmentMode($application)) {
            return;
        }

        if (! $document->application || (int) $document->application_id !== (int) $application->id) {
            abort(404);
        }

        $qualification = $document->qualification;
        if (! $qualification && $document->qualification_id) {
            $qualification = Qualification::query()->find($document->qualification_id);
        }

        self::assertQualificationEditable($application, $qualification);
    }

    public static function assertWorkspaceAccessible(Application $application, ?Qualification $qualification, bool $creatingNew): void
    {
        if ($creatingNew) {
            self::assertCanCreateQualification($application);

            return;
        }

        if (! self::isRestrictedAmendmentMode($application)) {
            return;
        }

        if (! $qualification) {
            throw new AuthorizationException('This application is awaiting qualification corrections. Open the returned qualification to continue.');
        }

        self::assertQualificationEditable($application, $qualification);
    }

    public static function officerCanReceiveReopenedQualification(?User $officer, string $reopenLevel): bool
    {
        if (! $officer || ! $officer->is_active) {
            return false;
        }

        if ($reopenLevel === 'level2') {
            return $officer->can('verification.level2.review');
        }

        return $officer->can('verification.level1.process');
    }
}
