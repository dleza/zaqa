<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Verification\Events\QualificationAssignedToLevel2Reviewer;
use App\Enums\AssignmentCategoryReviewLevel;
use App\Enums\VerificationState;
use App\Models\Qualification;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;
use App\Models\VerificationAssignmentCategoryUser;
use Illuminate\Support\Facades\DB;

class QualificationLevel2AutoAssignmentService
{
    public function __construct(
        private readonly QualificationAutoAssignmentService $categoryResolver,
        private readonly AssignmentService $assignments,
        private readonly AuditLogService $audit,
    ) {
    }

    public function autoAssignAfterLevel1Complete(Qualification $qualification, User $level1Actor, ?int $preferredLevel2UserId = null): AutoAssignmentResult
    {
        $qualification->loadMissing(['awardingInstitution', 'country', 'verificationAssignmentCategory']);

        if ($qualification->verification_state !== VerificationState::UnderLevel2Review) {
            return new AutoAssignmentResult(
                assigned: false,
                categoryId: null,
                assigneeUserId: null,
                failureReason: 'Qualification is not in under_level2_review state.',
                alreadyAssigned: false,
            );
        }

        if ($qualification->level2_review_owner_id) {
            return new AutoAssignmentResult(
                assigned: true,
                categoryId: $qualification->verification_assignment_category_id ? (int) $qualification->verification_assignment_category_id : null,
                assigneeUserId: (int) $qualification->level2_review_owner_id,
                failureReason: null,
                alreadyAssigned: true,
            );
        }

        if ($preferredLevel2UserId && $preferredLevel2UserId > 0) {
            $preferred = User::query()->find($preferredLevel2UserId);
            if ($this->isEligibleLevel2Officer($preferred)) {
                return $this->assignPreferredLevel2($qualification, $level1Actor, $preferred, 'Level 1 resubmitted after Level 2 correction');
            }
        }

        $category = $this->resolveCategory($qualification);
        if (! $category) {
            return $this->failAndPersist(
                qualification: $qualification,
                categoryId: $qualification->verification_assignment_category_id ? (int) $qualification->verification_assignment_category_id : null,
                reason: 'No assignment category found for Level 2 auto-assignment.',
                actor: $level1Actor,
            );
        }

        $eligibleMemberships = $this->eligibleMemberships($category);
        if ($eligibleMemberships->isEmpty()) {
            return $this->failAndPersist(
                qualification: $qualification,
                categoryId: (int) $category->id,
                reason: 'No available Level 2 officer for category.',
                actor: $level1Actor,
            );
        }

        $assignee = $this->selectAssignee($eligibleMemberships->all());
        if (! $assignee) {
            return $this->failAndPersist(
                qualification: $qualification,
                categoryId: (int) $category->id,
                reason: 'No available Level 2 officer for category.',
                actor: $level1Actor,
            );
        }

        $actor = $this->resolveSystemActor(excludingUserId: (int) $assignee->id) ?? $level1Actor;

        try {
            $assigned = $this->assignments->assignLevel2ReviewOwnerWithContext(
                qualification: $qualification,
                assignedBy: $actor,
                assignedTo: $assignee,
                context: [
                    'source' => 'auto',
                    'category_id' => (int) $category->id,
                    'reason' => 'Level 1 review completed',
                ],
            );
        } catch (\Throwable $e) {
            return $this->failAndPersist(
                qualification: $qualification,
                categoryId: (int) $category->id,
                reason: 'Level 2 auto-assignment failed: '.$e->getMessage(),
                actor: $level1Actor,
            );
        }

        DB::transaction(function () use ($category, $assignee) {
            VerificationAssignmentCategoryUser::query()
                ->where('verification_assignment_category_id', (int) $category->id)
                ->where('user_id', (int) $assignee->id)
                ->where('review_level', AssignmentCategoryReviewLevel::Level2->value)
                ->lockForUpdate()
                ->update(['last_assigned_at' => now()]);
        });

        $this->audit->record(
            eventType: 'verification.level2_auto_assignment_succeeded',
            module: 'Verification',
            actionName: 'level2_auto_assignment_succeeded',
            message: 'Qualification auto-assigned to Level 2 reviewer after Level 1 completion.',
            entityType: Qualification::class,
            entityId: (int) $assigned->id,
            metadata: [
                'qualification_id' => (int) $assigned->id,
                'category_id' => (int) $category->id,
                'assigned_to_user_id' => (int) $assignee->id,
            ],
            actor: $level1Actor,
        );

        event(new QualificationAssignedToLevel2Reviewer($assigned, $actor, $assignee, $category));

        return new AutoAssignmentResult(
            assigned: true,
            categoryId: (int) $category->id,
            assigneeUserId: (int) $assignee->id,
            failureReason: null,
            alreadyAssigned: false,
        );
    }

    private function resolveCategory(Qualification $qualification): ?VerificationAssignmentCategory
    {
        if ($qualification->verification_assignment_category_id) {
            $stored = VerificationAssignmentCategory::query()
                ->where('id', (int) $qualification->verification_assignment_category_id)
                ->where('is_active', true)
                ->first();

            if ($stored) {
                return $stored;
            }
        }

        ['category' => $category, 'ambiguous' => $ambiguous] = $this->categoryResolver->resolveCategoryForQualification($qualification);

        return $ambiguous ? null : $category;
    }

    /**
     * @return \Illuminate\Support\Collection<int, VerificationAssignmentCategoryUser>
     */
    private function eligibleMemberships(VerificationAssignmentCategory $category)
    {
        return VerificationAssignmentCategoryUser::query()
            ->with('user.roles')
            ->where('verification_assignment_category_id', (int) $category->id)
            ->where('review_level', AssignmentCategoryReviewLevel::Level2->value)
            ->where('is_active', true)
            ->where('is_available', true)
            ->where(function ($q) {
                $q->whereNull('unavailable_until')->orWhere('unavailable_until', '<=', now());
            })
            ->whereHas('user', function ($q) {
                $q->where('is_active', true)
                    ->whereNull('applicant_type')
                    ->whereHas('roles', fn ($r) => $r->where('name', 'Verification Officer Level 2'));
            })
            ->get();
    }

    /**
     * @param  array<int, VerificationAssignmentCategoryUser>  $memberships
     */
    private function selectAssignee(array $memberships): ?User
    {
        $userIds = array_values(array_unique(array_map(fn (VerificationAssignmentCategoryUser $m) => (int) $m->user_id, $memberships)));
        if ($userIds === []) {
            return null;
        }

        $workloads = Qualification::query()
            ->whereIn('level2_review_owner_id', $userIds)
            ->where('verification_state', VerificationState::UnderLevel2Review->value)
            ->selectRaw('level2_review_owner_id, count(*) as c')
            ->groupBy('level2_review_owner_id')
            ->pluck('c', 'level2_review_owner_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        usort($memberships, function (VerificationAssignmentCategoryUser $a, VerificationAssignmentCategoryUser $b) use ($workloads) {
            $wa = (int) ($workloads[(string) $a->user_id] ?? 0);
            $wb = (int) ($workloads[(string) $b->user_id] ?? 0);
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }

            $la = $a->last_assigned_at?->getTimestamp();
            $lb = $b->last_assigned_at?->getTimestamp();
            if ($la === null && $lb !== null) {
                return -1;
            }
            if ($la !== null && $lb === null) {
                return 1;
            }
            if ($la !== null && $lb !== null && $la !== $lb) {
                return $la <=> $lb;
            }

            return ((int) $a->user_id) <=> ((int) $b->user_id);
        });

        return $memberships[0]->user ?? null;
    }

    private function isEligibleLevel2Officer(?User $user): bool
    {
        if (! $user || ! $user->is_active) {
            return false;
        }
        if ($user->applicant_type !== null) {
            return false;
        }
        if (! $user->can('verification.level2.review')) {
            return false;
        }

        return $user->hasRole('Verification Officer Level 2');
    }

    private function assignPreferredLevel2(Qualification $qualification, User $level1Actor, User $assignee, string $reason): AutoAssignmentResult
    {
        $category = $this->resolveCategory($qualification);
        $actor = $this->resolveSystemActor(excludingUserId: (int) $assignee->id) ?? $level1Actor;

        try {
            $assigned = $this->assignments->assignLevel2ReviewOwnerWithContext(
                qualification: $qualification,
                assignedBy: $actor,
                assignedTo: $assignee,
                context: [
                    'source' => 'level2_correction_return',
                    'category_id' => $category?->id,
                    'reason' => $reason,
                ],
            );
        } catch (\Throwable $e) {
            return $this->failAndPersist(
                qualification: $qualification,
                categoryId: $category ? (int) $category->id : null,
                reason: 'Preferred Level 2 reassignment failed: '.$e->getMessage(),
                actor: $level1Actor,
            );
        }

        if ($category) {
            VerificationAssignmentCategoryUser::query()
                ->where('verification_assignment_category_id', (int) $category->id)
                ->where('user_id', (int) $assignee->id)
                ->where('review_level', AssignmentCategoryReviewLevel::Level2->value)
                ->update(['last_assigned_at' => now()]);
        }

        $this->audit->record(
            eventType: 'verification.level2_auto_assignment_succeeded',
            module: 'Verification',
            actionName: 'level2_preferred_reassignment_succeeded',
            message: 'Qualification reassigned to Level 2 officer after Level 1 correction.',
            entityType: Qualification::class,
            entityId: (int) $assigned->id,
            metadata: [
                'qualification_id' => (int) $assigned->id,
                'category_id' => $category?->id,
                'assigned_to_user_id' => (int) $assignee->id,
                'preferred_level2_return' => true,
            ],
            actor: $level1Actor,
        );

        event(new QualificationAssignedToLevel2Reviewer($assigned, $actor, $assignee, $category));

        return new AutoAssignmentResult(
            assigned: true,
            categoryId: $category ? (int) $category->id : null,
            assigneeUserId: (int) $assignee->id,
            failureReason: null,
            alreadyAssigned: false,
        );
    }

    private function resolveSystemActor(?int $excludingUserId = null): ?User
    {
        $actorId = config('auto_assignment.actor_user_id');
        if (is_numeric($actorId) && (int) $actorId > 0) {
            $u = User::query()->find((int) $actorId);
            if ($u && $excludingUserId !== null && (int) $u->id === (int) $excludingUserId) {
                // Fall through.
            } else {
                return $u;
            }
        }

        return User::query()
            ->role('Super Admin')
            ->when($excludingUserId !== null, fn ($q) => $q->where('id', '!=', (int) $excludingUserId))
            ->orderBy('id')
            ->first();
    }

    private function failAndPersist(Qualification $qualification, ?int $categoryId, string $reason, User $actor): AutoAssignmentResult
    {
        $this->audit->record(
            eventType: 'verification.level2_auto_assignment_failed',
            module: 'Verification',
            actionName: 'level2_auto_assignment_failed',
            message: 'No available Level 2 officer found for assignment category; routed to Level 2 pool.',
            entityType: Qualification::class,
            entityId: (int) $qualification->id,
            metadata: [
                'qualification_id' => (int) $qualification->id,
                'category_id' => $categoryId,
                'failure_reason' => $reason,
            ],
            actor: $actor,
        );

        return new AutoAssignmentResult(
            assigned: false,
            categoryId: $categoryId,
            assigneeUserId: null,
            failureReason: $reason,
            alreadyAssigned: false,
        );
    }
}
