<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Enums\ApplicationStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecisionService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly VerificationWorkflowService $workflow,
    ) {
    }

    public function approve(Application $application, User $actor, ?string $comment = null): Application
    {
        $comment = $comment !== null ? trim($comment) : null;

        return DB::transaction(function () use ($application, $actor, $comment) {
            $application->refresh();

            $before = [
                'current_status' => $application->current_status?->value ?? null,
                'verification_state' => $application->verification_state?->value ?? null,
                'approved_at' => optional($application->approved_at)?->toIso8601String(),
            ];

            $application->forceFill([
                'approved_at' => now(),
            ])->save();

            if ($comment) {
                ApplicationComment::create([
                    'application_id' => $application->id,
                    'author_user_id' => $actor->id,
                    'type' => 'decision_reason',
                    'visibility' => 'internal',
                    'body' => $comment,
                ]);
            }

            $application = $this->workflow->transition(
                application: $application,
                toVerificationState: VerificationState::ApprovedForCertificate,
                toApplicationStatus: ApplicationStatus::Approved,
                actor: $actor,
                eventType: 'decision',
                eventCode: 'decision.approved',
                stage: LifecycleStage::Decision,
                title: 'Approved',
                description: 'Your application was approved. Certificate issuance is in progress.',
                visibility: LifecycleVisibility::Both,
                comment: $comment,
                metadata: [
                    'approved_by_user_id' => $actor->id,
                ],
            );

            $after = [
                'current_status' => $application->current_status?->value ?? null,
                'verification_state' => $application->verification_state?->value ?? null,
                'approved_at' => optional($application->approved_at)?->toIso8601String(),
            ];

            $this->audit->record(
                eventType: 'verification.approved',
                module: 'Verification',
                actionName: 'approved',
                message: 'Application approved.',
                entityType: Application::class,
                entityId: $application->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'comment' => $comment,
                ],
                actor: $actor,
            );

            return $application;
        });
    }

    public function reject(Application $application, User $actor, string $reason): Application
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Reason is required.',
            ]);
        }

        return DB::transaction(function () use ($application, $actor, $reason) {
            $application->refresh();

            $before = [
                'current_status' => $application->current_status?->value ?? null,
                'verification_state' => $application->verification_state?->value ?? null,
                'rejected_at' => optional($application->rejected_at)?->toIso8601String(),
            ];

            $application->forceFill([
                'rejected_at' => now(),
            ])->save();

            ApplicationComment::create([
                'application_id' => $application->id,
                'author_user_id' => $actor->id,
                'type' => 'decision_reason',
                'visibility' => 'applicant_visible',
                'body' => $reason,
            ]);

            $application = $this->workflow->transition(
                application: $application,
                toVerificationState: VerificationState::Rejected,
                toApplicationStatus: ApplicationStatus::Rejected,
                actor: $actor,
                eventType: 'decision',
                eventCode: 'decision.rejected',
                stage: LifecycleStage::Decision,
                title: 'Rejected',
                description: 'Your application was rejected.',
                visibility: LifecycleVisibility::Both,
                comment: $reason,
                metadata: [
                    'rejected_by_user_id' => $actor->id,
                ],
            );

            $after = [
                'current_status' => $application->current_status?->value ?? null,
                'verification_state' => $application->verification_state?->value ?? null,
                'rejected_at' => optional($application->rejected_at)?->toIso8601String(),
            ];

            $this->audit->record(
                eventType: 'verification.rejected',
                module: 'Verification',
                actionName: 'rejected',
                message: 'Application rejected.',
                entityType: Application::class,
                entityId: $application->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            return $application;
        });
    }

    public function issueCertificate(Application $application, User $actor, ?string $comment = null): Application
    {
        $comment = $comment !== null ? trim($comment) : null;

        return DB::transaction(function () use ($application, $actor, $comment) {
            $application->refresh();

            if ($application->current_status !== ApplicationStatus::Approved) {
                throw ValidationException::withMessages([
                    'status' => 'Certificate can only be issued after approval.',
                ]);
            }

            $before = [
                'current_status' => $application->current_status?->value ?? null,
                'verification_state' => $application->verification_state?->value ?? null,
                'completed_at' => optional($application->completed_at)?->toIso8601String(),
            ];

            $application->forceFill([
                'completed_at' => $application->completed_at ?? now(),
            ])->save();

            if ($comment) {
                ApplicationComment::create([
                    'application_id' => $application->id,
                    'author_user_id' => $actor->id,
                    'type' => 'decision_reason',
                    'visibility' => 'internal',
                    'body' => $comment,
                ]);
            }

            $application = $this->workflow->transition(
                application: $application,
                toVerificationState: VerificationState::CertificateIssued,
                toApplicationStatus: ApplicationStatus::CertificateReady,
                actor: $actor,
                eventType: 'certificate',
                eventCode: 'certificate.issued',
                stage: LifecycleStage::Certificate,
                title: 'Certificate issued',
                description: 'Your certificate is ready.',
                visibility: LifecycleVisibility::Both,
                comment: $comment,
                metadata: [
                    'issued_by_user_id' => $actor->id,
                ],
            );

            $after = [
                'current_status' => $application->current_status?->value ?? null,
                'verification_state' => $application->verification_state?->value ?? null,
                'completed_at' => optional($application->completed_at)?->toIso8601String(),
            ];

            $this->audit->record(
                eventType: 'verification.certificate_issued',
                module: 'Verification',
                actionName: 'certificate_issued',
                message: 'Certificate issued (hook only).',
                entityType: Application::class,
                entityId: $application->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'comment' => $comment,
                ],
                actor: $actor,
            );

            return $application;
        });
    }
}

