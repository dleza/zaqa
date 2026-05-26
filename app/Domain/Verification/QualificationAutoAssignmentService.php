<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Enums\VerificationState;
use App\Models\Qualification;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;
use App\Models\VerificationAssignmentCategoryUser;
use Illuminate\Support\Facades\DB;

class QualificationAutoAssignmentService
{
    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly AuditLogService $audit,
    ) {
    }

    public function autoAssign(Qualification $qualification, ?User $actor = null, ?string $reason = null): AutoAssignmentResult
    {
        $actor ??= $this->resolveSystemActor();

        $qualification->loadMissing(['awardingInstitution', 'country']);

        // Idempotency: never reassign if already assigned.
        if ($qualification->assigned_verifier_id) {
            return new AutoAssignmentResult(
                assigned: true,
                categoryId: $qualification->verification_assignment_category_id ? (int) $qualification->verification_assignment_category_id : null,
                assigneeUserId: (int) $qualification->assigned_verifier_id,
                failureReason: null,
                alreadyAssigned: true,
            );
        }

        if ($qualification->verification_state !== VerificationState::AwaitingAssignment) {
            return $this->failAndPersist(
                qualification: $qualification,
                categoryId: null,
                reason: 'Qualification is not in awaiting_assignment state.',
                actor: $actor,
                contextReason: $reason,
            );
        }

        ['category' => $category, 'ambiguous' => $ambiguous] = $this->resolveCategoryMatch($qualification);

        if ($ambiguous) {
            return $this->failAndPersist(
                qualification: $qualification,
                categoryId: null,
                reason: 'Ambiguous assignment category mapping.',
                actor: $actor,
                contextReason: $reason,
            );
        }

        if (! $category) {
            $missing = $qualification->is_foreign_qualification
                ? ($qualification->country_id ? 'No active assignment category found' : 'Missing country for foreign qualification')
                : ($qualification->awarding_institution_id ? 'No active assignment category found' : 'Missing awarding institution for local qualification');

            return $this->failAndPersist(
                qualification: $qualification,
                categoryId: null,
                reason: $missing,
                actor: $actor,
                contextReason: $reason,
            );
        }

        $eligibleMemberships = $this->eligibleMemberships($category);
        if ($eligibleMemberships->isEmpty()) {
            return $this->failAndPersist(
                qualification: $qualification,
                categoryId: (int) $category->id,
                reason: 'No available Level 1 officer for category.',
                actor: $actor,
                contextReason: $reason,
            );
        }

        $assignee = $this->selectAssignee($eligibleMemberships->all());
        if (! $assignee) {
            return $this->failAndPersist(
                qualification: $qualification,
                categoryId: (int) $category->id,
                reason: 'No available Level 1 officer for category.',
                actor: $actor,
                contextReason: $reason,
            );
        }

        // Ensure the actor used for audit/history is not the same as the assignee.
        if ($actor && (int) $actor->id === (int) $assignee->id) {
            $actor = $this->resolveSystemActor(excludingUserId: (int) $assignee->id) ?? $actor;
        }

        try {
            $assigned = $this->assignments->assignWithContext(
                qualification: $qualification,
                assignedBy: $actor,
                assignedTo: $assignee,
                comment: null,
                context: [
                    'source' => 'auto',
                    'category_id' => (int) $category->id,
                    'reason' => $reason,
                ],
            );
        } catch (\Throwable $e) {
            return $this->failAndPersist(
                qualification: $qualification,
                categoryId: (int) $category->id,
                reason: 'Auto-assignment failed: '.$e->getMessage(),
                actor: $actor,
                contextReason: $reason,
            );
        }

        DB::transaction(function () use ($category, $assignee) {
            $lockedCat = VerificationAssignmentCategory::query()->lockForUpdate()->findOrFail($category->id);
            $lockedCat->forceFill([
                'last_assigned_user_id' => (int) $assignee->id,
                'last_assigned_at' => now(),
            ])->save();

            VerificationAssignmentCategoryUser::query()
                ->where('verification_assignment_category_id', (int) $category->id)
                ->where('user_id', (int) $assignee->id)
                ->lockForUpdate()
                ->update(['last_assigned_at' => now()]);
        });

        $this->audit->record(
            eventType: 'verification.auto_assignment_succeeded',
            module: 'Verification',
            actionName: 'auto_assignment_succeeded',
            message: 'Qualification auto-assigned to Level 1.',
            entityType: Qualification::class,
            entityId: (int) $assigned->id,
            metadata: [
                'qualification_id' => (int) $assigned->id,
                'category_id' => (int) $category->id,
                'assigned_to_user_id' => (int) $assignee->id,
                'reason' => $reason,
            ],
            actor: $actor,
        );

        return new AutoAssignmentResult(
            assigned: true,
            categoryId: (int) $category->id,
            assigneeUserId: (int) $assignee->id,
            failureReason: null,
            alreadyAssigned: false,
        );
    }

    /**
     * @return array{category: VerificationAssignmentCategory|null, ambiguous: bool}
     */
    private function resolveCategoryMatch(Qualification $qualification): array
    {
        if ($qualification->is_foreign_qualification) {
            if (! $qualification->country_id) {
                return ['category' => null, 'ambiguous' => false];
            }

            $matches = VerificationAssignmentCategory::query()
                ->where('type', 'foreign_country')
                ->where('is_active', true)
                ->whereHas('countries', fn ($q) => $q->where('countries.id', (int) $qualification->country_id))
                ->limit(2)
                ->get();

            if ($matches->count() > 1) {
                return ['category' => null, 'ambiguous' => true];
            }

            return ['category' => $matches->first(), 'ambiguous' => false];
        }

        if (! $qualification->awarding_institution_id) {
            return ['category' => null, 'ambiguous' => false];
        }

        $matches = VerificationAssignmentCategory::query()
            ->where('type', 'local_institution')
            ->where('is_active', true)
            ->whereHas('awardingInstitutions', fn ($q) => $q->where('awarding_institutions.id', (int) $qualification->awarding_institution_id))
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            return ['category' => null, 'ambiguous' => true];
        }

        return ['category' => $matches->first(), 'ambiguous' => false];
    }

    /**
     * @return \Illuminate\Support\Collection<int, VerificationAssignmentCategoryUser>
     */
    private function eligibleMemberships(VerificationAssignmentCategory $category)
    {
        return VerificationAssignmentCategoryUser::query()
            ->with('user.roles')
            ->where('verification_assignment_category_id', (int) $category->id)
            ->where('is_active', true)
            ->where('is_available', true)
            ->where(function ($q) {
                $q->whereNull('unavailable_until')->orWhere('unavailable_until', '<=', now());
            })
            ->whereHas('user', function ($q) {
                $q->where('is_active', true)
                    ->whereNull('applicant_type')
                    ->whereHas('roles', fn ($r) => $r->where('name', 'Verification Officer Level 1'));
            })
            ->get();
    }

    private function selectAssignee(array $memberships): ?User
    {
        $userIds = array_values(array_unique(array_map(fn (VerificationAssignmentCategoryUser $m) => (int) $m->user_id, $memberships)));
        if ($userIds === []) {
            return null;
        }

        $workloads = Qualification::query()
            ->whereIn('assigned_verifier_id', $userIds)
            ->whereIn('verification_state', [
                VerificationState::AssignedToLevel1->value,
                VerificationState::UnderLevel1Review->value,
            ])
            ->selectRaw('assigned_verifier_id, count(*) as c')
            ->groupBy('assigned_verifier_id')
            ->pluck('c', 'assigned_verifier_id')
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

    private function resolveSystemActor(?int $excludingUserId = null): ?User
    {
        $actorId = config('auto_assignment.actor_user_id');
        if (is_numeric($actorId) && (int) $actorId > 0) {
            $u = User::query()->find((int) $actorId);
            if ($u && $excludingUserId !== null && (int) $u->id === (int) $excludingUserId) {
                // Fall through to alternative actor resolution.
            } else {
                return $u;
            }
        }

        $superAdmin = User::query()
            ->role('Super Admin')
            ->when($excludingUserId !== null, fn ($q) => $q->where('id', '!=', (int) $excludingUserId))
            ->orderBy('id')
            ->first();

        if ($superAdmin) {
            return $superAdmin;
        }

        // Next preference: a Level 2 officer (avoids "assigner == assignee" for many categories).
        $level2 = User::query()
            ->role('Verification Officer Level 2')
            ->whereNull('applicant_type')
            ->where('is_active', true)
            ->when($excludingUserId !== null, fn ($q) => $q->where('id', '!=', (int) $excludingUserId))
            ->orderBy('id')
            ->first();

        if ($level2) {
            return $level2;
        }

        // Fallback: any active staff user (non-applicant) so assignment/audit can proceed.
        $staff = User::query()
            ->whereNull('applicant_type')
            ->where('is_active', true)
            ->when($excludingUserId !== null, fn ($q) => $q->where('id', '!=', (int) $excludingUserId))
            ->orderBy('id')
            ->first();

        if ($staff) {
            return $staff;
        }

        // Final fallback: create a local "System" actor. This is primarily for tests/early environments
        // where no staff users exist yet; it avoids failing auto-assignment due to missing actor.
        return $this->createSystemActor();
    }

    private function createSystemActor(): User
    {
        return User::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'System',
            'login_identifier_type' => null,
            'email' => null,
            'phone_primary' => null,
            'phone_secondary' => null,
            'password' => null,
            'applicant_type' => null,
            'is_active' => true,
        ]);
    }

    private function failAndPersist(Qualification $qualification, ?int $categoryId, string $reason, ?User $actor, ?string $contextReason): AutoAssignmentResult
    {
        DB::transaction(function () use ($qualification, $categoryId, $reason) {
            $locked = Qualification::query()->lockForUpdate()->findOrFail($qualification->id);
            if ($locked->assigned_verifier_id) {
                return;
            }
            if ($locked->verification_state !== VerificationState::AwaitingAssignment) {
                return;
            }

            $locked->forceFill([
                'verification_assignment_category_id' => $categoryId,
                'assignment_source' => 'auto',
                'assignment_failure_reason' => $reason,
                'auto_assigned_at' => null,
            ])->save();
        });

        $this->audit->record(
            eventType: 'verification.auto_assignment_failed',
            module: 'Verification',
            actionName: 'auto_assignment_failed',
            message: 'Qualification auto-assignment failed.',
            entityType: Qualification::class,
            entityId: (int) $qualification->id,
            metadata: [
                'qualification_id' => (int) $qualification->id,
                'category_id' => $categoryId,
                'failure_reason' => $reason,
                'reason' => $contextReason,
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
