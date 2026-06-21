<?php

namespace App\Domain\Verification;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Qualification;
use App\Models\User;
use App\Support\Search\ReferenceSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class QualificationsPoolService
{
    /**
     * Pending work queue for the logged-in verification officer (Assigned to me page only).
     */
    public function assignedToMe(Request $request, User $user): LengthAwarePaginator
    {
        $query = $this->qualificationListQuery($request);
        $this->applyAssignedToMeActionRequiredScope($query, $user);

        return $query
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Level 1 qualifications assigned to the officer that still require Level 1 action.
     *
     * @param  Builder<Qualification>  $query
     */
    public function assignedToLevel1AndAwaitingAction(Builder $query, User $user): void
    {
        $query
            ->where('qualifications.assigned_verifier_id', $user->id)
            ->whereIn('qualifications.verification_state', [
                VerificationState::AssignedToLevel1->value,
                VerificationState::UnderLevel1Review->value,
            ]);
    }

    /**
     * Level 2 qualifications owned or locked to the officer that still require Level 2 action.
     *
     * @param  Builder<Qualification>  $query
     */
    public function assignedToLevel2AndAwaitingAction(Builder $query, User $user): void
    {
        $query->where(function (Builder $outer) use ($user) {
            $outer->where(function (Builder $owned) use ($user) {
                $owned->where('qualifications.verification_state', VerificationState::UnderLevel2Review->value)
                    ->where('qualifications.level2_review_owner_id', $user->id);
            })->orWhere(function (Builder $locked) use ($user) {
                $locked->where('qualifications.verification_state', VerificationState::AutoVerifiedPendingLevel2->value)
                    ->where('qualifications.level2_review_locked_by', $user->id);
            });
        });
    }

    /**
     * Qualification-centric pool query for verification users.
     *
     * Filters:
     * - application_reference: application number prefix/exact
     * - qualification_reference: verification reference prefix/exact
     * - qualification_type_id: int
     * - awarding_institution_id: int
     * - country_id: int
     * - foreign: 1|0 (qualification locality)
     * - assigned: 1|0 (qualification assigned_verifier_id)
     * - assigned_verifier_id: int
     * - verification_state: string
     * - payment_status: paid|unpaid
     * - submitted_from/submitted_to: Y-m-d (application submitted_at)
     */
    public function pool(Request $request, ?int $currentUserId = null): LengthAwarePaginator
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $restrictLevel1 = VerificationQualificationAccess::mustRestrictToAssignedQualifications($viewer);
        // Controllers may set flags via Request::merge(); query() alone would miss them.
        $awaitingApplicantFromMe = ($request->query('awaiting_applicant_from_me')
            ?? $request->input('awaiting_applicant_from_me')) === '1';
        $mine = ($request->query('mine') ?? $request->input('mine')) === '1';
        $assigned = $request->query('assigned');
        $assignedVerifierId = $request->query('assigned_verifier_id');
        $verificationState = trim((string) $request->query('verification_state', ''));

        $query = $this->qualificationListQuery($request);

        if ($awaitingApplicantFromMe && $viewer) {
            $query->where('qualifications.verification_state', VerificationState::ReturnedToApplicant->value)
                ->where('qualifications.send_back_by_user_id', $viewer->id);
        } elseif ($restrictLevel1 && $viewer) {
            $query->where('assigned_verifier_id', $viewer->id);
        } else {
            if ($assigned === '1') {
                $query->whereNotNull('assigned_verifier_id');
            } elseif ($assigned === '0') {
                $query->whereNull('assigned_verifier_id');
            }

            if (is_string($assignedVerifierId) && $assignedVerifierId !== '') {
                $query->where('assigned_verifier_id', (int) $assignedVerifierId);
            }

            if ($mine && $currentUserId) {
                $query->where(function ($w) use ($currentUserId) {
                    $w->where('qualifications.assigned_verifier_id', $currentUserId)
                        ->orWhere(function ($w2) use ($currentUserId) {
                            $w2->where('qualifications.verification_state', VerificationState::UnderLevel2Review->value)
                                ->where('qualifications.level2_review_owner_id', $currentUserId);
                        });
                });
            }
        }

        if ($verificationState !== '') {
            $query->where('verification_state', $verificationState);
        } elseif (! $awaitingApplicantFromMe) {
            // Verification pool only shows actionable tasks by default.
            // Terminal outcomes (approved/rejected/issued/closed) should not remain in the pool even if the
            // parent application still has other pending qualification items.
            $query->where(function ($q) {
                $q->whereNull('qualifications.verification_state')
                    ->orWhereIn('qualifications.verification_state', [
                        VerificationState::AwaitingAssignment->value,
                        VerificationState::AssignedToLevel1->value,
                        VerificationState::UnderLevel1Review->value,
                        VerificationState::UnderLevel2Review->value,
                        VerificationState::AutoVerifiedPendingLevel2->value,
                    ]);
            });
        }

        return $query
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();
    }

    public function awaitingLevel1Assignment(Request $request): LengthAwarePaginator
    {
        $query = $this->qualificationListQuery($request);
        VerificationAssignmentQueueScopes::applyAwaitingLevel1AssignmentScope($query);
        $this->applyAssignmentQueueSort($query, $request);

        return $query->paginate(25)->withQueryString();
    }

    public function awaitingLevel2Assignment(Request $request): LengthAwarePaginator
    {
        $query = $this->qualificationListQuery($request);
        VerificationAssignmentQueueScopes::applyAwaitingLevel2AssignmentScope($query);
        $this->applyAssignmentQueueSort($query, $request);

        return $query->paginate(25)->withQueryString();
    }

    public function countAwaitingLevel1Assignment(): int
    {
        $query = Qualification::query();
        VerificationAssignmentQueueScopes::applyAwaitingLevel1AssignmentScope($query);

        return $query->count();
    }

    public function countAwaitingLevel2Assignment(): int
    {
        $query = Qualification::query();
        VerificationAssignmentQueueScopes::applyAwaitingLevel2AssignmentScope($query);

        return $query->count();
    }

    /**
     * @param  list<int>  $qualificationIds
     * @return list<int>
     */
    public function filterQualificationIdsInAwaitingLevel1Assignment(array $qualificationIds): array
    {
        if ($qualificationIds === []) {
            return [];
        }

        $query = Qualification::query()->whereIn('qualifications.id', $qualificationIds);
        VerificationAssignmentQueueScopes::applyAwaitingLevel1AssignmentScope($query);

        return $query->pluck('qualifications.id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Manual Level 2 owner assignment only (excludes auto-verified pending lock queue items).
     *
     * @param  list<int>  $qualificationIds
     * @return list<int>
     */
    public function filterQualificationIdsEligibleForLevel2OwnerAssignment(array $qualificationIds): array
    {
        if ($qualificationIds === []) {
            return [];
        }

        $query = Qualification::query()
            ->whereIn('qualifications.id', $qualificationIds)
            ->where('qualifications.verification_state', VerificationState::UnderLevel2Review->value)
            ->whereNull('qualifications.level2_review_owner_id');

        VerificationAssignmentQueueScopes::applyActiveSubmittedApplicationScope($query);

        return $query->pluck('qualifications.id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @param  Builder<Qualification>  $query
     */
    private function applyAssignmentQueueSort(Builder $query, Request $request): void
    {
        $sort = trim((string) $request->query('sort', 'deadline'));
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query->leftJoin('applications as assignment_queue_apps', 'assignment_queue_apps.id', '=', 'qualifications.application_id');
        $query->select('qualifications.*');

        match ($sort) {
            'submitted' => $query->orderBy('assignment_queue_apps.submitted_at', $direction),
            'application' => $query->orderBy('assignment_queue_apps.application_number', $direction),
            'reference' => $query->orderBy('qualifications.verification_reference_number', $direction),
            'country' => $query->orderBy('qualifications.country_id', $direction),
            'institution' => $query->orderBy('qualifications.awarding_institution_id', $direction),
            'type' => $query->orderBy('qualifications.qualification_type_id', $direction),
            default => $query
                ->orderByRaw(
                    'CASE WHEN COALESCE(qualifications.service_deadline_at, assignment_queue_apps.service_deadline_at) IS NULL THEN 1 ELSE 0 END'
                )
                ->orderByRaw(
                    'COALESCE(qualifications.service_deadline_at, assignment_queue_apps.service_deadline_at) '.$direction
                ),
        };

        $query->orderBy('qualifications.id', 'asc');
    }

    /**
     * @param  Builder<Qualification>  $query
     */
    private function applyAssignedToMeActionRequiredScope(Builder $query, User $user): void
    {
        $canLevel1 = $user->can('verification.level1.process');
        $canLevel2 = $user->can('verification.level2.review');

        $query->where(function (Builder $outer) use ($user, $canLevel1, $canLevel2) {
            if ($canLevel1) {
                $outer->where(function (Builder $level1) use ($user) {
                    $this->assignedToLevel1AndAwaitingAction($level1, $user);
                });
            }

            if ($canLevel2) {
                if ($canLevel1) {
                    $outer->orWhere(function (Builder $level2) use ($user) {
                        $this->assignedToLevel2AndAwaitingAction($level2, $user);
                    });
                } else {
                    $outer->where(function (Builder $level2) use ($user) {
                        $this->assignedToLevel2AndAwaitingAction($level2, $user);
                    });
                }
            }
        });
    }

    /**
     * Shared list filters for the verification qualifications pool and Assigned to me page.
     *
     * @return Builder<Qualification>
     */
    private function qualificationListQuery(Request $request): Builder
    {
        $applicationReference = (string) $request->query('application_reference', '');
        $qualificationReference = (string) $request->query('qualification_reference', '');
        $qualificationTypeId = $request->query('qualification_type_id');
        $awardingInstitutionId = $request->query('awarding_institution_id');
        $countryId = $request->query('country_id');
        $foreign = $request->query('foreign');
        $paymentStatus = trim((string) $request->query('payment_status', ''));
        $submittedFrom = trim((string) $request->query('submitted_from', ''));
        $submittedTo = trim((string) $request->query('submitted_to', ''));
        $overdue = $request->query('overdue');
        $overdueDays = $request->query('overdue_days');

        $query = Qualification::query()
            ->with([
                'application.applicant',
                'qualificationTypeMaster',
                'awardingInstitution',
                'country',
                'assignedVerifier',
                'level2ReviewOwner',
            ])
            ->whereHas('application', function ($aq) use ($submittedFrom, $submittedTo, $paymentStatus) {
                $aq->whereIn('current_status', [
                    ApplicationStatus::Submitted,
                    ApplicationStatus::Resubmitted,
                    ApplicationStatus::InProgress,
                    ApplicationStatus::SentBack,
                ]);

                if ($submittedFrom !== '') {
                    $aq->whereDate('submitted_at', '>=', $submittedFrom);
                }
                if ($submittedTo !== '') {
                    $aq->whereDate('submitted_at', '<=', $submittedTo);
                }

                if ($paymentStatus === 'paid') {
                    $aq->whereNotNull('paid_at');
                } elseif ($paymentStatus === 'unpaid') {
                    $aq->whereNull('paid_at');
                }
            });

        if ($overdue === '1') {
            $this->applyQualificationOverdueFilter($query, now());
        }

        if (is_string($overdueDays) && $overdueDays !== '') {
            $days = (int) $overdueDays;
            if (in_array($days, [30, 60, 90], true)) {
                $this->applyQualificationOverdueFilter($query, now()->subDays($days));
            }
        }

        ReferenceSearch::applyToQualificationQuery($query, $applicationReference, $qualificationReference);

        if (is_string($qualificationTypeId) && $qualificationTypeId !== '') {
            $query->where('qualification_type_id', (int) $qualificationTypeId);
        }
        if (is_string($awardingInstitutionId) && $awardingInstitutionId !== '') {
            $query->where('awarding_institution_id', (int) $awardingInstitutionId);
        }
        if (is_string($countryId) && $countryId !== '') {
            $query->where('country_id', (int) $countryId);
        }

        if ($foreign === '1') {
            $query->where('is_foreign_qualification', true);
        } elseif ($foreign === '0') {
            $query->where('is_foreign_qualification', false);
        }

        return $query;
    }

    private function applyQualificationOverdueFilter(Builder $query, Carbon $cutoff): void
    {
        $this->applyOpenQualificationSlaScope($query);

        $query->where(function (Builder $deadline) use ($cutoff) {
            $deadline
                ->where(function (Builder $direct) use ($cutoff) {
                    $direct->whereNotNull('qualifications.service_deadline_at')
                        ->where('qualifications.service_deadline_at', '<', $cutoff);
                })
                ->orWhere(function (Builder $fallback) use ($cutoff) {
                    $fallback->whereNull('qualifications.service_deadline_at')
                        ->whereHas('application', function (Builder $application) use ($cutoff) {
                            $application->whereNotNull('service_deadline_at')
                                ->where('service_deadline_at', '<', $cutoff);
                        });
                });
        });
    }

    private function applyOpenQualificationSlaScope(Builder $query): void
    {
        $query
            ->where(function (Builder $states) {
                $states->whereNull('qualifications.verification_state')
                    ->orWhereNotIn('qualifications.verification_state', QualificationSlaService::CLOSED_QUALIFICATION_STATES);
            })
            ->whereHas('application', function (Builder $application) {
                $application->whereNotIn('current_status', QualificationSlaService::CLOSED_APPLICATION_STATUSES);
            });
    }
}
