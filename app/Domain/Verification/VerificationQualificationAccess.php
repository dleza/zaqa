<?php

namespace App\Domain\Verification;

use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Assignment-scoped visibility for per-qualification verification tasks.
 *
 * Level 1 officers (have {@see User::can('verification.level1.process')} but cannot assign or run Level 2 review)
 * may only access qualifications assigned to them. Level 2 / Super Admin bypass via assign or level2.review permissions.
 */
final class VerificationQualificationAccess
{
    /**
     * True when the user must be scoped to qualifications where they are the assigned Level 1 verifier.
     */
    public static function mustRestrictToAssignedQualifications(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if (! $user->can('verification.level1.process')) {
            return false;
        }
        if ($user->can('verification.assign')) {
            return false;
        }
        if ($user->can('verification.level2.review')) {
            return false;
        }

        return true;
    }

    public static function ensureQualificationAccessible(?User $user, Qualification $qualification): void
    {
        if (! self::mustRestrictToAssignedQualifications($user)) {
            return;
        }
        if ((int) $qualification->assigned_verifier_id === (int) $user->id) {
            return;
        }
        // Officer may follow up on items they sent back while still awaiting applicant amendment.
        $vs = $qualification->verification_state;
        if ($vs === VerificationState::ReturnedToApplicant
            && (int) ($qualification->send_back_by_user_id ?? 0) === (int) $user->id) {
            return;
        }

        abort(403);
    }

    /**
     * Level 1 scoped users may open an application only if at least one qualification on it is assigned to them.
     */
    public static function ensureApplicationHasAssignableQualification(?User $user, Application $application): void
    {
        if (! self::mustRestrictToAssignedQualifications($user)) {
            return;
        }
        $exists = $application->qualifications()
            ->where('assigned_verifier_id', $user->id)
            ->exists();
        if (! $exists) {
            abort(403);
        }
    }

    /**
     * Applications visible to a restricted Level 1 user (has at least one qualification assigned to them).
     *
     * @return Builder<Application>
     */
    public static function applicationsWithQualificationAssignedTo(User $user): Builder
    {
        return Application::query()->whereHas(
            'qualifications',
            fn (Builder $q) => $q->where('assigned_verifier_id', $user->id)
        );
    }

    /**
     * When non-null, pool/grouping queries must only include qualifications assigned to this verifier.
     */
    public static function restrictedVerifierIdForQueries(?User $user): ?int
    {
        if (! $user || ! self::mustRestrictToAssignedQualifications($user)) {
            return null;
        }

        return $user->id;
    }
}
