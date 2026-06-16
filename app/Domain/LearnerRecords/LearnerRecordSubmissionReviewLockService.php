<?php

namespace App\Domain\LearnerRecords;

use App\Models\LearnerRecordSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LearnerRecordSubmissionReviewLockService
{
    public function ttlMinutes(): int
    {
        return 30;
    }

    public function isExpired(?\Illuminate\Support\Carbon $lockedAt): bool
    {
        if (! $lockedAt) {
            return true;
        }

        return $lockedAt->lt(now()->subMinutes($this->ttlMinutes()));
    }

    public function lock(LearnerRecordSubmission $submission, User $actor): LearnerRecordSubmission
    {
        return DB::transaction(function () use ($submission, $actor) {
            $locked = LearnerRecordSubmission::query()
                ->lockForUpdate()
                ->with('reviewLockedBy')
                ->findOrFail($submission->id);

            $expired = $this->isExpired($locked->review_locked_at);
            $isSuperAdmin = (bool) $actor->hasRole('Super Admin');

            if (! $locked->review_locked_by_user_id || $expired || $isSuperAdmin || (int) $locked->review_locked_by_user_id === (int) $actor->id) {
                $locked->forceFill([
                    'review_locked_by_user_id' => $actor->id,
                    'review_locked_at' => now(),
                ])->save();

                return $locked;
            }

            $ownerName = $locked->reviewLockedBy?->name ?? 'another officer';
            throw ValidationException::withMessages([
                'lock' => "This submission is currently being reviewed by {$ownerName}.",
            ]);
        });
    }

    public function unlock(LearnerRecordSubmission $submission, User $actor): LearnerRecordSubmission
    {
        return DB::transaction(function () use ($submission, $actor) {
            $locked = LearnerRecordSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->id);

            $expired = $this->isExpired($locked->review_locked_at);
            $isOwner = (int) ($locked->review_locked_by_user_id ?? 0) === (int) $actor->id;
            $isSuperAdmin = (bool) $actor->hasRole('Super Admin');

            if (! $locked->review_locked_by_user_id || $expired) {
                $this->clearLock($locked);

                return $locked->fresh();
            }

            if (! $isOwner && ! $isSuperAdmin) {
                throw ValidationException::withMessages([
                    'lock' => 'You cannot release a lock held by another officer.',
                ]);
            }

            $this->clearLock($locked);

            return $locked->fresh();
        });
    }

    public function assertActorHoldsLockOrIsSuperAdmin(LearnerRecordSubmission $submission, User $actor): void
    {
        if ($actor->hasRole('Super Admin')) {
            return;
        }

        $lockedBy = (int) ($submission->review_locked_by_user_id ?? 0);
        $lockedAt = $submission->review_locked_at;

        if (! $lockedBy || $this->isExpired($lockedAt)) {
            throw ValidationException::withMessages([
                'lock' => 'Start review to lock this submission before taking review actions.',
            ]);
        }

        if ($lockedBy !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'lock' => 'This submission is currently locked by another officer.',
            ]);
        }
    }

    public function clearLock(LearnerRecordSubmission $submission): void
    {
        $submission->forceFill([
            'review_locked_by_user_id' => null,
            'review_locked_at' => null,
        ])->save();
    }

    /**
     * @return array{
     *   is_locked: bool,
     *   locked_by_user_id: int|null,
     *   locked_by_name: string|null,
     *   locked_at: string|null,
     *   expires_at: string|null
     * }
     */
    public function serializeLock(LearnerRecordSubmission $submission): array
    {
        $lockExpired = $this->isExpired($submission->review_locked_at);
        $isLocked = (bool) $submission->review_locked_by_user_id && ! $lockExpired;
        $expiresAt = $submission->review_locked_at
            ? $submission->review_locked_at->copy()->addMinutes($this->ttlMinutes())
            : null;

        return [
            'is_locked' => $isLocked,
            'locked_by_user_id' => $isLocked ? (int) $submission->review_locked_by_user_id : null,
            'locked_by_name' => $isLocked ? ($submission->reviewLockedBy?->name ?? null) : null,
            'locked_at' => $isLocked ? optional($submission->review_locked_at)?->toIso8601String() : null,
            'expires_at' => $isLocked ? $expiresAt?->toIso8601String() : null,
        ];
    }

    public function nextPendingSubmissionId(int $currentSubmissionId): ?int
    {
        $nextId = LearnerRecordSubmission::query()
            ->pending()
            ->where('id', '>', $currentSubmissionId)
            ->orderBy('id')
            ->value('id');

        return $nextId !== null ? (int) $nextId : null;
    }
}
