<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Documents\ApplicantDocumentService;
use App\Domain\Verification\Events\QualificationLevel1Completed;
use App\Enums\DocumentType;
use App\Enums\VerificationState;
use App\Models\Qualification;
use App\Models\QualificationAssignment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationLevel1ReviewService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicantDocumentService $documents,
    ) {}

    public function completeLevel1(Qualification $qualification, User $actor, string $findings, ?UploadedFile $attachment = null): Qualification
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

        return DB::transaction(function () use ($qualification, $actor, $findings, $attachment) {
            $qualification->refresh();
            $qualification->loadMissing('application');

            $vs = $qualification->verification_state;
            $allowed = [VerificationState::AssignedToLevel1, VerificationState::UnderLevel1Review];
            if (! $vs instanceof VerificationState || ! in_array($vs, $allowed, true)) {
                throw ValidationException::withMessages([
                    'qualification' => 'Level 1 cannot complete review for this qualification in its current state.',
                ]);
            }

            $assignedBy = QualificationAssignment::query()
                ->with('assignedBy')
                ->where('qualification_id', $qualification->id)
                ->whereNotNull('assigned_by_user_id')
                ->orderByDesc('assigned_at')
                ->first();

            $before = [
                'verification_state' => $qualification->verification_state?->value ?? null,
                'reviewer_notes' => $qualification->reviewer_notes,
                'reviewed_at' => optional($qualification->reviewed_at)?->toIso8601String(),
                'level2_review_owner_id' => $qualification->level2_review_owner_id,
            ];

            $qualification->forceFill([
                'verification_state' => VerificationState::UnderLevel2Review,
                'reviewer_notes' => $findings,
                'reviewed_at' => now(),
                'level2_review_owner_id' => $assignedBy?->assigned_by_user_id,
            ])->save();

            $attachmentDocumentId = null;
            if ($attachment instanceof UploadedFile && $attachment->isValid()) {
                $application = $qualification->application;
                $document = $this->documents->upload(
                    $application,
                    DocumentType::Level1ReviewAttachment,
                    $attachment,
                    $actor,
                    $qualification,
                );
                $attachmentDocumentId = $document->id;
            }

            $after = [
                'verification_state' => $qualification->verification_state?->value ?? null,
                'reviewer_notes' => $qualification->reviewer_notes,
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
                    'level1_attachment_document_id' => $attachmentDocumentId,
                ],
                actor: $actor,
            );

            if ($assignedBy?->assignedBy) {
                event(new QualificationLevel1Completed($qualification, $actor, $assignedBy->assignedBy, $findings));
            }

            return $qualification;
        });
    }
}
