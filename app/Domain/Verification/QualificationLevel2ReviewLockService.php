<?php

namespace App\Domain\Verification;

use App\Models\Qualification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationLevel2ReviewLockService
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

    public function lock(Qualification $qualification, User $actor): Qualification
    {
        return DB::transaction(function () use ($qualification, $actor) {
            $locked = Qualification::query()
                ->lockForUpdate()
                ->with('level2ReviewLockedBy')
                ->findOrFail($qualification->id);

            $expired = $this->isExpired($locked->level2_review_locked_at);
            $isSuperAdmin = (bool) $actor->hasRole('Super Admin');

            if (! $locked->level2_review_locked_by || $expired || $isSuperAdmin || (int) $locked->level2_review_locked_by === (int) $actor->id) {
                $locked->forceFill([
                    'level2_review_locked_by' => $actor->id,
                    'level2_review_locked_at' => now(),
                ])->save();

                return $locked;
            }

            $ownerName = $locked->level2ReviewLockedBy?->name ?? 'another officer';
            throw ValidationException::withMessages([
                'lock' => "This qualification is currently being reviewed by {$ownerName}.",
            ]);
        });
    }

    public function unlock(Qualification $qualification, User $actor): Qualification
    {
        return DB::transaction(function () use ($qualification, $actor) {
            $locked = Qualification::query()
                ->lockForUpdate()
                ->findOrFail($qualification->id);

            $expired = $this->isExpired($locked->level2_review_locked_at);
            $isOwner = (int) ($locked->level2_review_locked_by ?? 0) === (int) $actor->id;
            $isSuperAdmin = (bool) $actor->hasRole('Super Admin');

            if (! $locked->level2_review_locked_by || $expired) {
                $locked->forceFill([
                    'level2_review_locked_by' => null,
                    'level2_review_locked_at' => null,
                ])->save();

                return $locked;
            }

            if (! $isOwner && ! $isSuperAdmin) {
                throw ValidationException::withMessages([
                    'lock' => 'You cannot release a lock held by another officer.',
                ]);
            }

            $locked->forceFill([
                'level2_review_locked_by' => null,
                'level2_review_locked_at' => null,
            ])->save();

            return $locked;
        });
    }

    public function assertActorHoldsLockOrIsSuperAdmin(Qualification $qualification, User $actor): void
    {
        $lockedBy = (int) ($qualification->level2_review_locked_by ?? 0);
        $lockedAt = $qualification->level2_review_locked_at;

        if (! $lockedBy || $this->isExpired($lockedAt)) {
            throw ValidationException::withMessages([
                'lock' => 'Start review to lock this qualification before taking Level 2 actions.',
            ]);
        }

        if ($actor->hasRole('Super Admin')) {
            return;
        }

        if ($lockedBy !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'lock' => 'This qualification is currently locked by another officer.',
            ]);
        }
    }

    public function clearLock(Qualification $qualification): void
    {
        $qualification->forceFill([
            'level2_review_locked_by' => null,
            'level2_review_locked_at' => null,
        ])->save();
    }
}
