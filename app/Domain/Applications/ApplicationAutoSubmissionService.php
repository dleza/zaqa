<?php

namespace App\Domain\Applications;

use App\Domain\Applications\Events\ApplicationSubmitted;
use App\Domain\Audit\AuditLogService;
use App\Domain\Payments\ApplicationPaymentSatisfaction;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Domain\Verification\QualificationSlaService;
use App\Enums\ApplicationStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Payment confirmation is now the submission trigger.
 *
 * This service is the single entry point for converting a paid/satisfied application into a locked,
 * submitted application and kicking off the (async) qualification auto-verification pipeline.
 */
class ApplicationAutoSubmissionService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
        private readonly ApplicationPaymentSatisfaction $paymentSatisfaction,
        private readonly QualificationSlaService $qualificationSla,
        private readonly ReferenceNumberService $referenceNumbers,
    ) {}

    public function submitAfterPaymentSatisfied(Application $application, ?Payment $payment = null, ?User $actor = null): Application
    {
        return DB::transaction(function () use ($application, $payment, $actor) {
            $application = Application::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency: only auto-submit once (initial submission). Supplementary/top-up payments must not
            // re-submit the application.
            if ($application->submitted_at) {
                return $application;
            }

            $application->loadMissing(['qualifications', 'documents', 'invoice', 'payments', 'applicant']);

            if (! $this->paymentSatisfaction->isSatisfied($application)) {
                return $application;
            }

            $fromStatus = $application->current_status ?? ApplicationStatus::Draft;
            $toStatus = ApplicationStatus::Submitted;

            $now = now();

            $before = [
                'current_status' => $fromStatus instanceof ApplicationStatus ? $fromStatus->value : (string) $fromStatus,
                'verification_state' => $application->verification_state?->value ?? null,
                'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
                'service_deadline_at' => optional($application->service_deadline_at)?->toIso8601String(),
                'assigned_level1_user_id' => $application->assigned_level1_user_id,
                'assigned_by_level2_user_id' => $application->assigned_by_level2_user_id,
            ];

            $application->forceFill([
                'current_status' => $toStatus,
                'verification_state' => VerificationState::AwaitingAutoVerification,
                'submitted_at' => $now,
                'assigned_level1_user_id' => null,
                'assigned_by_level2_user_id' => null,
            ])->save();

            ApplicationStatusHistory::create([
                'application_id' => $application->id,
                'from_status' => $fromStatus instanceof ApplicationStatus ? $fromStatus->value : (string) $fromStatus,
                'to_status' => $toStatus->value,
                'changed_by_user_id' => $actor?->id ?? $application->applicant_user_id,
                'comment' => 'Application auto-submitted after payment confirmation.',
                'changed_at' => $now,
                'metadata' => [
                    'trigger' => 'payment_confirmation',
                    'payment_id' => $payment?->id,
                    'invoice_id' => $payment?->invoice_id ?? $application->invoice?->id,
                ],
            ]);

            $this->referenceNumbers->assignQualificationVerificationReferences($application);

            $application->loadMissing('qualifications');
            foreach ($application->qualifications as $qualification) {
                /** @var Qualification $qualification */
                if ($qualification->verification_state === null || $qualification->verification_state === VerificationState::AwaitingAssignment) {
                    $qualification->forceFill([
                        'verification_state' => VerificationState::AwaitingAutoVerification,
                        'returned_to_applicant_at' => null,
                    ])->save();
                }
            }

            $this->qualificationSla->applyApplicationSla(
                application: $application,
                startedAt: $now,
            );
            $application->refresh();

            $after = [
                'current_status' => $application->current_status?->value ?? (string) $application->current_status,
                'verification_state' => $application->verification_state?->value ?? null,
                'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
                'service_deadline_at' => optional($application->service_deadline_at)?->toIso8601String(),
                'assigned_level1_user_id' => $application->assigned_level1_user_id,
                'assigned_by_level2_user_id' => $application->assigned_by_level2_user_id,
            ];

            $this->audit->record(
                eventType: 'applications.auto_submitted',
                module: 'Applications',
                actionName: 'auto_submitted',
                message: 'Application auto-submitted after payment confirmation.',
                entityType: Application::class,
                entityId: $application->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'payment_id' => $payment?->id,
                    'payment_method' => $payment?->method?->value ?? null,
                    'payment_provider' => $payment?->provider ?? null,
                    'invoice_id' => $payment?->invoice_id ?? $application->invoice?->id,
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $application,
                eventType: 'submission',
                eventCode: 'submission.auto_submitted',
                stage: LifecycleStage::Submitted,
                title: 'Application submitted',
                description: 'Your application has been automatically submitted for verification after payment confirmation.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'payment_id' => $payment?->id,
                    'invoice_id' => $payment?->invoice_id ?? $application->invoice?->id,
                ],
                occurredAt: $now,
            );

            $application->loadMissing('applicant');
            $notifyApplicant = $application->applicant;
            if ($notifyApplicant) {
                event(new ApplicationSubmitted($application, $notifyApplicant, false));
            }

            return $application;
        });
    }
}
