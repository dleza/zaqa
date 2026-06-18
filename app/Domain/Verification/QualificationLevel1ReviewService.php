<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Documents\ApplicantDocumentService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Domain\Verification\Events\QualificationLevel1Completed;
use App\Enums\DocumentType;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationLevel1ReviewService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicantDocumentService $documents,
        private readonly ApplicationLifecycleService $lifecycle,
        private readonly QualificationLevel2AutoAssignmentService $level2AutoAssignment,
    ) {}

    /**
     * Move an assigned qualification into active Level 1 review when the assignee opens it.
     */
    public function beginReviewIfAssigned(Qualification $qualification, User $actor): Qualification
    {
        if (! $actor->can('verification.level1.process')) {
            return $qualification;
        }

        if ((int) $qualification->assigned_verifier_id !== (int) $actor->id) {
            return $qualification;
        }

        $vs = $qualification->verification_state;
        if ($vs === VerificationState::UnderLevel1Review) {
            return $qualification;
        }

        if ($vs !== VerificationState::AssignedToLevel1) {
            return $qualification;
        }

        return DB::transaction(function () use ($qualification, $actor) {
            $qualification->refresh();
            $qualification->loadMissing('application');

            $vs = $qualification->verification_state;
            if ($vs === VerificationState::UnderLevel1Review) {
                return $qualification;
            }
            if ($vs !== VerificationState::AssignedToLevel1) {
                return $qualification;
            }
            if ((int) $qualification->assigned_verifier_id !== (int) $actor->id) {
                return $qualification;
            }

            $before = [
                'verification_state' => $qualification->verification_state?->value ?? null,
            ];

            $qualification->forceFill([
                'verification_state' => VerificationState::UnderLevel1Review,
            ])->save();

            $after = [
                'verification_state' => $qualification->verification_state?->value ?? null,
            ];

            $application = $qualification->application;
            if ($application) {
                $this->lifecycle->event(
                    application: $application,
                    eventType: 'verification',
                    eventCodeBase: 'verification.level1_review_started.q'.$qualification->id,
                    stage: LifecycleStage::Review,
                    title: 'Level 1 review in progress',
                    description: 'Level 1 officer opened this qualification for review.',
                    visibility: LifecycleVisibility::Internal,
                    actor: $actor,
                    metadata: [
                        'qualification_id' => $qualification->id,
                        'assigned_verifier_id' => $actor->id,
                    ],
                    occurredAt: now(),
                );
            }

            $this->audit->record(
                eventType: 'verification.level1_review_started',
                module: 'Verification',
                actionName: 'level1_review_started',
                message: 'Level 1 review started.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $qualification->application_id,
                    'assigned_verifier_id' => $actor->id,
                ],
                actor: $actor,
            );

            return $qualification->fresh();
        });
    }

    public function completeLevel1(
        Qualification $qualification,
        User $actor,
        string $findings,
        bool $recommendedForAward,
        int $qualificationTypeId,
        ?string $accreditationStatement = null,
        ?UploadedFile $supportingAttachment = null,
        ?UploadedFile $evaluationReport = null,
    ): Qualification {
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

        $accreditationStatement = $accreditationStatement !== null ? trim($accreditationStatement) : null;
        if ($accreditationStatement === '') {
            $accreditationStatement = null;
        }

        if ($recommendedForAward && $accreditationStatement === null) {
            throw ValidationException::withMessages([
                'accreditation_statement' => 'Accreditation statement is required when recommending award.',
            ]);
        }

        $qualificationType = QualificationType::query()->find($qualificationTypeId);
        if (! $qualificationType) {
            throw ValidationException::withMessages([
                'qualification_type_id' => 'Invalid qualification type.',
            ]);
        }

        $oldTypeId = (int) ($qualification->qualification_type_id ?? 0);
        if ($qualificationTypeId !== $oldTypeId && ! $qualificationType->is_active) {
            throw ValidationException::withMessages([
                'qualification_type_id' => 'Selected qualification type is not available.',
            ]);
        }

        return DB::transaction(function () use (
            $qualification,
            $actor,
            $findings,
            $recommendedForAward,
            $qualificationTypeId,
            $qualificationType,
            $oldTypeId,
            $accreditationStatement,
            $supportingAttachment,
            $evaluationReport,
        ) {
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
                'level1_recommended_for_award' => $qualification->level1_recommended_for_award,
                'level1_accreditation_statement' => $qualification->level1_accreditation_statement,
                'qualification_type_id' => $qualification->qualification_type_id,
                'qualification_type' => $qualification->qualification_type,
                'reviewed_at' => optional($qualification->reviewed_at)?->toIso8601String(),
                'level2_review_owner_id' => $qualification->level2_review_owner_id,
            ];

            $oldTypeCode = (string) ($qualification->qualification_type ?? '');

            $wasLevel2Correction = $qualification->returned_to_level1_at !== null
                && (int) ($qualification->level1_correction_cycle ?? 0) > 0;
            $preferredLevel2UserId = (int) ($qualification->level2_return_target_user_id ?? 0);
            $originalLevel2SenderId = (int) ($qualification->returned_to_level1_by_user_id ?? 0);
            $correctionCycle = (int) ($qualification->level1_correction_cycle ?? 0);

            $qualification->forceFill([
                'verification_state' => VerificationState::UnderLevel2Review,
                'reviewer_notes' => $findings,
                'level1_recommended_for_award' => $recommendedForAward,
                'level1_accreditation_statement' => $accreditationStatement,
                'qualification_type_id' => $qualificationTypeId,
                'qualification_type' => $qualificationType->zqf_level_code,
                'reviewed_at' => now(),
                'level1_review_completed_by_user_id' => $actor->id,
                'level2_review_owner_id' => null,
                'returned_to_level1_at' => null,
                'returned_to_level1_to_user_id' => null,
            ])->save();

            $application = $qualification->application;

            if ($oldTypeId !== $qualificationTypeId && $application) {
                $oldType = $oldTypeId > 0 ? QualificationType::query()->find($oldTypeId) : null;
                $oldTypeName = $oldType?->name ?? '—';
                $newTypeName = (string) $qualificationType->name;

                $this->lifecycle->event(
                    application: $application,
                    eventType: 'verification',
                    eventCodeBase: 'verification.level1_qualification_type_corrected.q'.$qualification->id,
                    stage: LifecycleStage::Review,
                    title: 'Level 1 qualification type corrected',
                    description: "Level 1 corrected qualification type from {$oldTypeName} to {$newTypeName}.",
                    visibility: LifecycleVisibility::Internal,
                    actor: $actor,
                    metadata: [
                        'qualification_id' => $qualification->id,
                        'old_qualification_type_id' => $oldTypeId > 0 ? $oldTypeId : null,
                        'old_qualification_type_label' => $oldTypeName,
                        'new_qualification_type_id' => $qualificationTypeId,
                        'new_qualification_type_label' => $newTypeName,
                        'changed_during_level1_completion' => true,
                    ],
                    occurredAt: now(),
                );

                $this->audit->record(
                    eventType: 'verification.level1_qualification_type_corrected',
                    module: 'Verification',
                    actionName: 'level1_qualification_type_corrected',
                    message: "Level 1 corrected qualification type from {$oldTypeName} to {$newTypeName}.",
                    entityType: Qualification::class,
                    entityId: $qualification->id,
                    beforeState: [
                        'qualification_type_id' => $oldTypeId > 0 ? $oldTypeId : null,
                        'qualification_type' => $oldTypeCode !== '' ? $oldTypeCode : null,
                    ],
                    afterState: [
                        'qualification_type_id' => $qualificationTypeId,
                        'qualification_type' => $qualificationType->zqf_level_code,
                    ],
                    metadata: [
                        'application_id' => $qualification->application_id,
                        'old_qualification_type_id' => $oldTypeId > 0 ? $oldTypeId : null,
                        'old_qualification_type_label' => $oldTypeName,
                        'new_qualification_type_id' => $qualificationTypeId,
                        'new_qualification_type_label' => $newTypeName,
                        'changed_during_level1_completion' => true,
                    ],
                    actor: $actor,
                );
            }
            $supportingAttachmentDocumentId = null;
            if ($supportingAttachment instanceof UploadedFile && $supportingAttachment->isValid()) {
                $document = $this->documents->upload(
                    $application,
                    DocumentType::Level1ReviewAttachment,
                    $supportingAttachment,
                    $actor,
                    $qualification,
                );
                $supportingAttachmentDocumentId = $document->id;
            }

            $evaluationReportDocumentId = null;
            if ($evaluationReport instanceof UploadedFile && $evaluationReport->isValid()) {
                $document = $this->documents->upload(
                    $application,
                    DocumentType::Level1EvaluationReport,
                    $evaluationReport,
                    $actor,
                    $qualification,
                );
                $evaluationReportDocumentId = $document->id;
            }

            $l2Result = $this->level2AutoAssignment->autoAssignAfterLevel1Complete(
                $qualification,
                $actor,
                $wasLevel2Correction && $preferredLevel2UserId > 0 ? $preferredLevel2UserId : null,
            );
            $qualification->refresh();

            if ($wasLevel2Correction && $application) {
                $this->lifecycle->event(
                    application: $application,
                    eventType: 'verification',
                    eventCodeBase: 'verification.level1_resubmitted_after_level2_send_back.q'.$qualification->id.'.'.$correctionCycle,
                    stage: LifecycleStage::Review,
                    title: 'Level 1 corrections resubmitted',
                    description: 'Level 1 resubmitted review after Level 2 correction request.',
                    visibility: LifecycleVisibility::Internal,
                    actor: $actor,
                    metadata: [
                        'qualification_id' => $qualification->id,
                        'level1_actor_user_id' => $actor->id,
                        'original_level2_sender_user_id' => $originalLevel2SenderId > 0 ? $originalLevel2SenderId : null,
                        'assigned_level2_user_id' => $l2Result->assigneeUserId,
                        'correction_cycle' => $correctionCycle,
                    ],
                    occurredAt: now(),
                );

                $this->audit->record(
                    eventType: 'verification.level1_resubmitted_after_level2_send_back',
                    module: 'Verification',
                    actionName: 'level1_resubmitted_after_level2_send_back',
                    message: 'Level 1 resubmitted review after Level 2 correction request.',
                    entityType: Qualification::class,
                    entityId: $qualification->id,
                    beforeState: [
                        'correction_cycle' => $correctionCycle,
                        'was_returned_to_level1' => $wasLevel2Correction,
                    ],
                    afterState: [
                        'verification_state' => $qualification->verification_state?->value ?? null,
                        'level2_review_owner_id' => $qualification->level2_review_owner_id,
                    ],
                    metadata: [
                        'application_id' => $qualification->application_id,
                        'original_level2_sender_user_id' => $originalLevel2SenderId > 0 ? $originalLevel2SenderId : null,
                        'assigned_level2_user_id' => $l2Result->assigneeUserId,
                        'correction_cycle' => $correctionCycle,
                    ],
                    actor: $actor,
                );
            }

            $after = [
                'verification_state' => $qualification->verification_state?->value ?? null,
                'reviewer_notes' => $qualification->reviewer_notes,
                'level1_recommended_for_award' => $qualification->level1_recommended_for_award,
                'level1_accreditation_statement' => $qualification->level1_accreditation_statement,
                'qualification_type_id' => $qualification->qualification_type_id,
                'qualification_type' => $qualification->qualification_type,
                'reviewed_at' => optional($qualification->reviewed_at)?->toIso8601String(),
                'level2_review_owner_id' => $qualification->level2_review_owner_id,
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
                    'recommended_for_award' => $recommendedForAward,
                    'has_accreditation_statement' => $accreditationStatement !== null,
                    'level1_supporting_attachment_document_id' => $supportingAttachmentDocumentId,
                    'level1_evaluation_report_document_id' => $evaluationReportDocumentId,
                    'level2_auto_assigned' => $l2Result->assigned && ! $l2Result->alreadyAssigned,
                    'level2_auto_assignment_failure' => $l2Result->failureReason,
                ],
                actor: $actor,
            );

            if ($l2Result->assigned && $l2Result->assigneeUserId) {
                $level2Reviewer = User::query()->find($l2Result->assigneeUserId);
                if ($level2Reviewer) {
                    event(new QualificationLevel1Completed(
                        $qualification,
                        $actor,
                        $level2Reviewer,
                        $findings,
                        $recommendedForAward,
                    ));
                }
            }

            return $qualification;
        });
    }
}
