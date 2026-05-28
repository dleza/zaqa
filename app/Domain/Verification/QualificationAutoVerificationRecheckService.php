<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Enums\VerificationState;
use App\Jobs\Verification\ProcessQualificationAutoVerificationJob;
use App\Models\ApplicationComment;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class QualificationAutoVerificationRecheckService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {
    }

    public function queue(Qualification $qualification, User $actor): AutoVerificationRecheckResult
    {
        $qualification->loadMissing(['application']);

        $beforeState = $qualification->only([
            'verification_state',
            'assigned_verifier_id',
            'assignment_failure_reason',
            'institution_pull_lookup_dispatched_at',
            'institution_pull_lookup_attempted_at',
            'institution_pull_lookup_status',
            'institution_pull_lookup_last_error',
        ]);

        $resumeState = $this->shouldPreserveCurrentState($qualification)
            ? ($qualification->verification_state?->value ?? (string) $qualification->verification_state)
            : null;
        $resumeAssigneeId = $resumeState !== null && $qualification->assigned_verifier_id
            ? (int) $qualification->assigned_verifier_id
            : null;

        DB::transaction(function () use ($qualification, $actor, $beforeState) {
            $locked = Qualification::query()
                ->with('application')
                ->lockForUpdate()
                ->findOrFail($qualification->id);

            $locked->forceFill([
                'institution_pull_lookup_dispatched_at' => null,
                'institution_pull_lookup_attempted_at' => null,
                'institution_pull_lookup_status' => null,
                'institution_pull_lookup_last_error' => null,
            ])->save();

            if ($locked->application) {
                ApplicationComment::query()->create([
                    'application_id' => (int) $locked->application_id,
                    'qualification_id' => (int) $locked->id,
                    'author_user_id' => (int) $actor->id,
                    'type' => 'verification_note',
                    'visibility' => 'internal',
                    'body' => 'Auto-verification recheck queued by '.$actor->name.'.',
                ]);
            }

            $this->audit->record(
                eventType: 'verification.auto_verification_recheck_queued',
                module: 'Verification',
                actionName: 'auto_verification_recheck_queued',
                message: 'Auto-verification recheck queued by '.$actor->name.'.',
                entityType: Qualification::class,
                entityId: (int) $locked->id,
                beforeState: $beforeState,
                afterState: $locked->only([
                    'verification_state',
                    'assigned_verifier_id',
                    'assignment_failure_reason',
                    'institution_pull_lookup_dispatched_at',
                    'institution_pull_lookup_attempted_at',
                    'institution_pull_lookup_status',
                    'institution_pull_lookup_last_error',
                ]),
                metadata: [
                    'application_id' => (int) $locked->application_id,
                    'qualification_id' => (int) $locked->id,
                    'previous_verification_state' => $beforeState['verification_state'] ?? null,
                    'previous_assigned_verifier_id' => $beforeState['assigned_verifier_id'] ?? null,
                    'previous_assignment_failure_reason' => $beforeState['assignment_failure_reason'] ?? null,
                ],
                actor: $actor,
            );
        });

        ProcessQualificationAutoVerificationJob::dispatch(
            (int) $qualification->id,
            true,
            $resumeState,
            $resumeAssigneeId,
        );

        return new AutoVerificationRecheckResult(
            queued: true,
            message: 'Auto-verification recheck has been queued.',
        );
    }

    private function shouldPreserveCurrentState(Qualification $qualification): bool
    {
        $state = $qualification->verification_state;

        return $state instanceof VerificationState
            && ! in_array($state, [VerificationState::AwaitingAutoVerification, VerificationState::AwaitingAssignment], true);
    }
}
