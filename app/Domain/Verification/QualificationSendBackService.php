<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Domain\Verification\Events\QualificationSentBackToApplicant;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\ApplicationComment;
use App\Models\Qualification;
use App\Models\QualificationAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationSendBackService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
    ) {}

    public function sendBackToApplicant(Qualification $qualification, User $actor, string $comment): Qualification
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw ValidationException::withMessages([
                'comment' => 'Comment is required.',
            ]);
        }

        return DB::transaction(function () use ($qualification, $actor, $comment) {
            $qualification->refresh();
            $qualification->loadMissing('application');
            $application = $qualification->application;

            $this->assertCanSendBack($qualification);

            QualificationAssignment::query()
                ->where('qualification_id', $qualification->id)
                ->whereNull('unassigned_at')
                ->lockForUpdate()
                ->update(['unassigned_at' => now()]);

            ApplicationComment::create([
                'application_id' => $application->id,
                'qualification_id' => $qualification->id,
                'author_user_id' => $actor->id,
                'type' => 'send_back',
                'visibility' => 'applicant_visible',
                'body' => $comment,
            ]);

            $before = [
                'verification_state' => $qualification->verification_state?->value ?? (string) ($qualification->verification_state ?? ''),
                'assigned_verifier_id' => $qualification->assigned_verifier_id,
                'returned_to_applicant_at' => optional($qualification->returned_to_applicant_at)?->toIso8601String(),
            ];

            $qualification->forceFill([
                'assigned_verifier_id' => null,
                'assigned_at' => null,
                'verification_state' => VerificationState::ReturnedToApplicant,
                'returned_to_applicant_at' => now(),
            ])->save();

            $after = [
                'verification_state' => $qualification->verification_state?->value ?? null,
                'assigned_verifier_id' => $qualification->assigned_verifier_id,
                'returned_to_applicant_at' => optional($qualification->returned_to_applicant_at)?->toIso8601String(),
            ];

            $this->lifecycle->event(
                application: $application,
                eventType: 'review',
                eventCodeBase: 'review.qualification_sent_back.q'.$qualification->id,
                stage: LifecycleStage::SentBack,
                title: 'Qualification sent back for amendments',
                description: 'ZAQA requires corrections or additional information for one qualification item.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                comment: $comment,
                metadata: [
                    'qualification_id' => $qualification->id,
                    'send_back_by_user_id' => $actor->id,
                ],
                occurredAt: now(),
            );

            $this->audit->record(
                eventType: 'verification.qualification_sent_back_to_applicant',
                module: 'Verification',
                actionName: 'qualification_sent_back',
                message: 'Qualification sent back to applicant.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $application->id,
                    'comment' => $comment,
                ],
                actor: $actor,
            );

            event(new QualificationSentBackToApplicant($qualification, $application, $actor, $comment));

            return $qualification;
        });
    }

    private function assertCanSendBack(Qualification $qualification): void
    {
        $vs = $qualification->verification_state;

        if ($vs === VerificationState::ReturnedToApplicant) {
            throw ValidationException::withMessages([
                'qualification' => 'This qualification is already with the applicant for amendment.',
            ]);
        }

        $blocked = [
            VerificationState::ApprovedForCertificate,
            VerificationState::Rejected,
            VerificationState::CertificateIssued,
            VerificationState::Closed,
        ];

        if ($vs instanceof VerificationState && in_array($vs, $blocked, true)) {
            throw ValidationException::withMessages([
                'qualification' => 'This qualification cannot be sent back in its current state.',
            ]);
        }
    }
}
