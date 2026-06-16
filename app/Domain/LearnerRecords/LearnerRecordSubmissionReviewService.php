<?php

namespace App\Domain\LearnerRecords;

use App\Domain\Audit\AuditLogService;
use App\Enums\LearnerRecordReviewDecision;
use App\Enums\LearnerRecordSubmissionStatus;
use App\Models\LearnerRecord;
use App\Models\LearnerRecordSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LearnerRecordSubmissionReviewService
{
    public function __construct(
        private readonly LearnerRecordSubmissionPromotionService $promotion,
        private readonly AuditLogService $audit,
        private readonly LearnerRecordSubmissionReviewLockService $locks,
    ) {}

    public function approveAsNew(LearnerRecordSubmission $submission, User $actor, ?string $notes = null): LearnerRecordSubmission
    {
        $this->assertPending($submission);

        return DB::transaction(function () use ($submission, $actor, $notes) {
            $locked = LearnerRecordSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $this->assertPending($locked);
            $this->locks->assertActorHoldsLockOrIsSuperAdmin($locked, $actor);

            $result = $this->promotion->promoteAsNew($locked);
            $record = $result['record'];

            $locked->forceFill([
                'status' => LearnerRecordSubmissionStatus::Approved,
                'review_decision' => LearnerRecordReviewDecision::ApproveNew,
                'approved_learner_record_id' => (int) $record->id,
                'reviewed_by_user_id' => (int) $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ])->save();

            $this->incrementBatchApprovedCount((int) $locked->batch_id);

            $this->auditApproval($locked, $actor, 'approve_new', (int) $record->id, null, null);

            $this->audit->record(
                eventType: 'learner_record.created_from_submission',
                module: 'LearnerRecords',
                actionName: 'learner_record_created_from_submission',
                message: 'Learner record created from approved submission.',
                entityType: LearnerRecord::class,
                entityId: (int) $record->id,
                metadata: [
                    'submission_id' => (int) $locked->id,
                    'learner_record_id' => (int) $record->id,
                    'reviewer_id' => (int) $actor->id,
                ],
                actor: $actor,
            );

            $this->locks->clearLock($locked);

            return $locked->fresh();
        });
    }

    public function approveAsUpdate(
        LearnerRecordSubmission $submission,
        User $actor,
        int $targetLearnerRecordId,
        ?string $notes = null,
        bool $allowOverwrite = false,
    ): LearnerRecordSubmission {
        $this->assertPending($submission);

        return DB::transaction(function () use ($submission, $actor, $targetLearnerRecordId, $notes, $allowOverwrite) {
            $locked = LearnerRecordSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $this->assertPending($locked);
            $this->locks->assertActorHoldsLockOrIsSuperAdmin($locked, $actor);

            $target = LearnerRecord::query()->findOrFail($targetLearnerRecordId);

            $result = $this->promotion->promoteAsUpdate($locked, $target, $allowOverwrite);
            $record = $result['record'];

            $locked->forceFill([
                'status' => LearnerRecordSubmissionStatus::Approved,
                'review_decision' => LearnerRecordReviewDecision::ApproveUpdate,
                'target_learner_record_id' => $targetLearnerRecordId,
                'approved_learner_record_id' => (int) $record->id,
                'reviewed_by_user_id' => (int) $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ])->save();

            $this->incrementBatchApprovedCount((int) $locked->batch_id);

            $this->auditApproval($locked, $actor, 'approve_update', (int) $record->id, $result['before'], $result['after']);

            $this->audit->record(
                eventType: 'learner_record.updated_from_submission',
                module: 'LearnerRecords',
                actionName: 'learner_record_updated_from_submission',
                message: 'Learner record updated from approved submission.',
                entityType: LearnerRecord::class,
                entityId: (int) $record->id,
                beforeState: $result['before'],
                afterState: $result['after'],
                metadata: [
                    'submission_id' => (int) $locked->id,
                    'learner_record_id' => (int) $record->id,
                    'reviewer_id' => (int) $actor->id,
                ],
                actor: $actor,
            );

            $this->locks->clearLock($locked);

            return $locked->fresh();
        });
    }

    public function reject(LearnerRecordSubmission $submission, User $actor, string $reason): LearnerRecordSubmission
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['review_notes' => 'Rejection reason is required.']);
        }

        $this->assertPending($submission);

        return DB::transaction(function () use ($submission, $actor, $reason) {
            $locked = LearnerRecordSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $this->assertPending($locked);
            $this->locks->assertActorHoldsLockOrIsSuperAdmin($locked, $actor);

            $locked->forceFill([
                'status' => LearnerRecordSubmissionStatus::Rejected,
                'reviewed_by_user_id' => (int) $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $reason,
            ])->save();

            $this->incrementBatchRejectedCount((int) $locked->batch_id);

            $this->audit->record(
                eventType: 'learner_record_submission.rejected',
                module: 'LearnerRecords',
                actionName: 'learner_record_submission_rejected',
                message: 'Learner record submission rejected.',
                entityType: LearnerRecordSubmission::class,
                entityId: (int) $locked->id,
                metadata: [
                    'submission_id' => (int) $locked->id,
                    'batch_id' => $locked->batch_id,
                    'reviewer_id' => (int) $actor->id,
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            $this->locks->clearLock($locked);

            return $locked->fresh();
        });
    }

    public function markDuplicate(
        LearnerRecordSubmission $submission,
        User $actor,
        string $reason,
        ?int $targetLearnerRecordId = null,
    ): LearnerRecordSubmission {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['review_notes' => 'Duplicate reason is required.']);
        }

        $this->assertPending($submission);

        return DB::transaction(function () use ($submission, $actor, $reason, $targetLearnerRecordId) {
            $locked = LearnerRecordSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $this->assertPending($locked);
            $this->locks->assertActorHoldsLockOrIsSuperAdmin($locked, $actor);

            $locked->forceFill([
                'status' => LearnerRecordSubmissionStatus::Duplicate,
                'review_decision' => LearnerRecordReviewDecision::RejectDuplicate,
                'target_learner_record_id' => $targetLearnerRecordId,
                'reviewed_by_user_id' => (int) $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $reason,
            ])->save();

            $this->incrementBatchDuplicateCount((int) $locked->batch_id);

            $this->audit->record(
                eventType: 'learner_record_submission.marked_duplicate',
                module: 'LearnerRecords',
                actionName: 'learner_record_submission_marked_duplicate',
                message: 'Learner record submission marked as duplicate.',
                entityType: LearnerRecordSubmission::class,
                entityId: (int) $locked->id,
                metadata: [
                    'submission_id' => (int) $locked->id,
                    'target_learner_record_id' => $targetLearnerRecordId,
                    'reviewer_id' => (int) $actor->id,
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            $this->locks->clearLock($locked);

            return $locked->fresh();
        });
    }

    private function assertPending(LearnerRecordSubmission $submission): void
    {
        if ($submission->status !== LearnerRecordSubmissionStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Only pending submissions can be reviewed.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function auditApproval(
        LearnerRecordSubmission $submission,
        User $actor,
        string $decision,
        int $learnerRecordId,
        ?array $before,
        ?array $after,
    ): void {
        $this->audit->record(
            eventType: 'learner_record_submission.approved',
            module: 'LearnerRecords',
            actionName: 'learner_record_submission_approved',
            message: 'Learner record submission approved.',
            entityType: LearnerRecordSubmission::class,
            entityId: (int) $submission->id,
            beforeState: $before,
            afterState: $after,
            metadata: [
                'submission_id' => (int) $submission->id,
                'batch_id' => $submission->batch_id,
                'batch_reference' => $submission->batch?->reference,
                'decision' => $decision,
                'learner_record_id' => $learnerRecordId,
                'reviewer_id' => (int) $actor->id,
            ],
            actor: $actor,
        );
    }

    private function incrementBatchApprovedCount(?int $batchId): void
    {
        if (! $batchId) {
            return;
        }

        DB::table('learner_record_submission_batches')
            ->where('id', $batchId)
            ->update([
                'approved_count' => DB::raw('approved_count + 1'),
                'pending_count' => DB::raw('GREATEST(pending_count - 1, 0)'),
            ]);
    }

    private function incrementBatchRejectedCount(?int $batchId): void
    {
        if (! $batchId) {
            return;
        }

        DB::table('learner_record_submission_batches')
            ->where('id', $batchId)
            ->update([
                'rejected_count' => DB::raw('rejected_count + 1'),
                'pending_count' => DB::raw('GREATEST(pending_count - 1, 0)'),
            ]);
    }

    private function incrementBatchDuplicateCount(?int $batchId): void
    {
        if (! $batchId) {
            return;
        }

        DB::table('learner_record_submission_batches')
            ->where('id', $batchId)
            ->update([
                'duplicate_count' => DB::raw('duplicate_count + 1'),
                'pending_count' => DB::raw('GREATEST(pending_count - 1, 0)'),
            ]);
    }
}
