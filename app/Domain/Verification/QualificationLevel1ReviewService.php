<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Verification\Events\QualificationLevel1Completed;
use App\Enums\VerificationState;
use App\Models\Qualification;
use App\Models\QualificationAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationLevel1ReviewService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    public function completeLevel1(Qualification $qualification, User $actor, string $findings): Qualification
    {
        if ((int) $qualification->assigned_verifier_id !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'assignment' => 'This qualification task is not assigned to you.',
            ]);
        }

        $findings = trim($findings);
        if ($findings === '') {
            throw ValidationException::withMessages([
                'findings' => 'Findings are required.',
            ]);
        }

        return DB::transaction(function () use ($qualification, $actor, $findings) {
            $qualification->refresh();
            $qualification->loadMissing('application');

            $vs = $qualification->verification_state;
            $allowed = [VerificationState::AssignedToLevel1, VerificationState::UnderLevel1Review];
            if (! $vs instanceof VerificationState || ! in_array($vs, $allowed, true)) {
                throw ValidationException::withMessages([
                    'qualification' => 'Level 1 cannot complete review for this qualification in its current state.',
                ]);
            }

            $before = [
                'verification_state' => $qualification->verification_state?->value ?? null,
                'reviewer_notes' => $qualification->reviewer_notes,
                'reviewed_at' => optional($qualification->reviewed_at)?->toIso8601String(),
            ];

            $qualification->forceFill([
                'verification_state' => VerificationState::UnderLevel2Review,
                'reviewer_notes' => $findings,
                'reviewed_at' => now(),
            ])->save();

            $after = [
                'verification_state' => $qualification->verification_state?->value ?? null,
                'reviewer_notes' => $qualification->reviewer_notes,
                'reviewed_at' => optional($qualification->reviewed_at)?->toIso8601String(),
            ];

            $this->audit->record(
                eventType: 'verification.qualification_level1_completed',
                module: 'Verification',
                actionName: 'qualification_level1_completed',
                message: 'Level 1 completed review for a qualification item.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $qualification->application_id,
                    'findings' => $findings,
                ],
                actor: $actor,
            );

            $assignedBy = QualificationAssignment::query()
                ->with('assignedBy')
                ->where('qualification_id', $qualification->id)
                ->whereNotNull('assigned_by_user_id')
                ->orderByDesc('assigned_at')
                ->first()?->assignedBy;

            if ($assignedBy) {
                event(new QualificationLevel1Completed($qualification, $actor, $assignedBy, $findings));
            }

            return $qualification;
        });
    }
}
