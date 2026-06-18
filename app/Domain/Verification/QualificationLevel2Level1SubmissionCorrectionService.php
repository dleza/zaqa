<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;

class QualificationLevel2Level1SubmissionCorrectionService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
    ) {}

    /**
     * @param  'approval'|'rejection'  $decisionContext
     */
    public function applyLevel2CorrectionsToLevel1Submission(
        Qualification $qualification,
        User $actor,
        string $findings,
        ?string $accreditationStatement,
        string $decisionContext,
    ): void {
        $findings = trim($findings);
        $accreditationStatement = $accreditationStatement !== null ? trim($accreditationStatement) : null;
        if ($accreditationStatement === '') {
            $accreditationStatement = null;
        }

        $oldFindings = trim((string) ($qualification->reviewer_notes ?? ''));
        $oldAccreditation = trim((string) ($qualification->level1_accreditation_statement ?? ''));

        $changes = [];
        $updates = [];

        if ($findings !== $oldFindings) {
            $changes['findings'] = [
                'from' => $oldFindings,
                'to' => $findings,
            ];
            $updates['reviewer_notes'] = $findings !== '' ? $findings : null;
        }

        $newAccreditation = $accreditationStatement ?? '';
        if ($newAccreditation !== $oldAccreditation) {
            $changes['accreditation_statement'] = [
                'from' => $oldAccreditation,
                'to' => $newAccreditation,
            ];
            $updates['level1_accreditation_statement'] = $accreditationStatement;
        }

        if ($changes === []) {
            return;
        }

        $before = $qualification->only([
            'reviewer_notes',
            'level1_accreditation_statement',
        ]);

        $qualification->forceFill($updates)->save();

        $after = $qualification->only([
            'reviewer_notes',
            'level1_accreditation_statement',
        ]);

        $qualification->loadMissing('application');
        $application = $qualification->application;
        if (! $application instanceof Application) {
            return;
        }

        $changedFieldNames = array_keys($changes);

        $this->lifecycle->event(
            application: $application,
            eventType: 'verification',
            eventCodeBase: 'verification.level2_corrected_level1_submission.q'.$qualification->id,
            stage: LifecycleStage::Review,
            title: 'Level 2 corrected Level 1 submission',
            description: 'Level 2 corrected Level 1 submission fields during final decision.',
            visibility: LifecycleVisibility::Internal,
            actor: $actor,
            comment: null,
            metadata: [
                'qualification_id' => $qualification->id,
                'changed_fields' => $changedFieldNames,
                'decision_context' => $decisionContext,
            ],
            occurredAt: now(),
        );

        $this->audit->record(
            eventType: 'verification.level2_corrected_level1_submission',
            module: 'Verification',
            actionName: 'level2_corrected_level1_submission',
            message: 'Level 2 corrected Level 1 submission fields during final decision.',
            entityType: Qualification::class,
            entityId: $qualification->id,
            beforeState: $before,
            afterState: $after,
            metadata: [
                'application_id' => $application->id,
                'qualification_id' => $qualification->id,
                'level2_actor_id' => $actor->id,
                'decision_context' => $decisionContext,
                'changed_fields' => $changedFieldNames,
                'old_findings' => $changes['findings']['from'] ?? $oldFindings,
                'new_findings' => $changes['findings']['to'] ?? $oldFindings,
                'old_accreditation_statement' => $changes['accreditation_statement']['from'] ?? $oldAccreditation,
                'new_accreditation_statement' => $changes['accreditation_statement']['to'] ?? $oldAccreditation,
            ],
            actor: $actor,
        );
    }
}
