<?php

namespace App\Jobs\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Certificates\QualificationCertificateService;
use App\Domain\LearnerRecords\LearnerRecordMatchingService;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\LearnerRecord;
use App\Models\LearnerRecordMatchAttempt;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\User;
use App\Domain\Tracking\ApplicationLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 job: attempts internal Learner Record matching and applies an outcome.
 *
 * - Strong match (confidence >= threshold, non-ambiguous) => auto-verify
 * - If auto-issue enabled => issue certificate idempotently
 * - Otherwise => route to Level 2 review (auto_verified_pending_level2)
 * - Weak/no match => fall back to Level 1 assignment pool (awaiting_assignment)
 */
class ProcessQualificationAutoVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $qualificationId,
    ) {
    }

    public function handle(
        AuditLogService $audit,
        ApplicationLifecycleService $lifecycle,
        LearnerRecordMatchingService $matching,
        QualificationCertificateService $certificates,
    ): void
    {
        $qualification = Qualification::query()
            ->with(['application', 'learnerRecord'])
            ->find($this->qualificationId);

        if (! $qualification) {
            return;
        }

        if ($qualification->verification_state !== VerificationState::AwaitingAutoVerification) {
            return;
        }

        $threshold = (int) config('auto_verification.threshold', 70);
        $autoIssueEnabled = (bool) config('auto_verification.auto_issue_enabled', false);
        $enabled = (bool) config('auto_verification.enabled', true);

        $match = $enabled ? $matching->match($qualification) : null;

        $result = DB::transaction(function () use ($qualification, $match, $threshold, $autoIssueEnabled, $enabled) {
            $locked = Qualification::query()->lockForUpdate()->findOrFail($qualification->id);
            $locked->loadMissing('application');

            if ($locked->verification_state !== VerificationState::AwaitingAutoVerification) {
                return [
                    'changed' => false,
                    'application_id' => (int) $locked->application_id,
                    'action' => 'noop',
                    'qualification_id' => (int) $locked->id,
                ];
            }

            $locked->forceFill([
                'auto_verification_attempted_at' => $locked->auto_verification_attempted_at ?? now(),
            ])->save();

            if (! $enabled || $match === null) {
                $locked->forceFill([
                    'auto_verification_status' => null,
                    'auto_verification_confidence' => null,
                    'auto_verification_failure_reason' => 'auto_verification_disabled',
                    'auto_verification_match_summary' => null,
                    'verification_source' => null,
                    'learner_record_id' => null,
                    'auto_verified_at' => null,
                ])->save();

                $locked->forceFill([
                    'verification_state' => VerificationState::AwaitingAssignment,
                    'assigned_verifier_id' => null,
                    'assigned_at' => null,
                    'level2_review_owner_id' => null,
                ])->save();

                return [
                    'changed' => true,
                    'application_id' => (int) $locked->application_id,
                    'action' => 'fallback_level1',
                    'qualification_id' => (int) $locked->id,
                ];
            }

            LearnerRecordMatchAttempt::query()->create([
                'qualification_id' => $locked->id,
                'learner_record_id' => $match->learnerRecordId,
                'status' => $match->status,
                'confidence' => $match->confidence,
                'source' => $match->source,
                'matched_fields' => $match->matchedFields,
                'candidate_record_ids' => $match->candidateRecordIds,
                'failure_reason' => $match->failureReason,
            ]);

            $locked->forceFill([
                'auto_verification_status' => $match->status,
                'auto_verification_confidence' => $match->confidence,
                'auto_verification_failure_reason' => $match->failureReason,
                'auto_verification_match_summary' => [
                    'matched_fields' => $match->matchedFields,
                    'candidate_record_ids' => $match->candidateRecordIds,
                ],
            ])->save();

            $action = 'fallback_level1';

            if ($match->isMatchedAndSafe($threshold) && $match->learnerRecordId) {
                $learnerRecord = LearnerRecord::query()->find($match->learnerRecordId);
                $verifiedTitle = $learnerRecord?->program_of_study ? (string) $learnerRecord->program_of_study : null;

                $locked->forceFill([
                    'learner_record_id' => $match->learnerRecordId,
                    'auto_verified_at' => now(),
                    'verification_source' => 'internal_learner_record',
                    'verified_qualification_title' => $verifiedTitle,
                    'qualification_title_source' => $verifiedTitle ? \App\Enums\QualificationTitleSource::AutoVerifiedInternal : ($locked->qualification_title_source ?? null),
                ])->save();

                if ($autoIssueEnabled) {
                    $locked->forceFill([
                        'verification_state' => VerificationState::ApprovedForCertificate,
                    ])->save();

                    $action = 'issue_certificate';
                } else {
                    $locked->forceFill([
                        'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
                        'assigned_verifier_id' => null,
                        'assigned_at' => null,
                    ])->save();
                    $action = 'pending_level2';
                }
            } else {
                // Ambiguous/possible matches must never be auto-issued; route to Level 2 when confidence is high.
                if (in_array($match->status, [\App\Enums\LearnerRecordMatchStatus::Ambiguous, \App\Enums\LearnerRecordMatchStatus::PossibleMatch], true)
                    && $match->confidence >= $threshold) {
                    $locked->forceFill([
                        'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
                        'assigned_verifier_id' => null,
                        'assigned_at' => null,
                    ])->save();
                    $action = 'pending_level2';
                } else {
                    $locked->forceFill([
                        'verification_state' => VerificationState::AwaitingAssignment,
                        'assigned_verifier_id' => null,
                        'assigned_at' => null,
                        'level2_review_owner_id' => null,
                    ])->save();
                    $action = 'fallback_level1';
                }
            }

            // When all qualifications leave the auto-verification stage, the application re-enters
            // the standard verification intake state.
            $application = $locked->application;
            if ($application && $application->verification_state === VerificationState::AwaitingAutoVerification) {
                $remaining = Qualification::query()
                    ->where('application_id', $application->id)
                    ->where('verification_state', VerificationState::AwaitingAutoVerification->value)
                    ->exists();

                if (! $remaining) {
                    $application->forceFill([
                        'verification_state' => VerificationState::AwaitingAssignment,
                    ])->save();
                }
            }

            return [
                'changed' => true,
                'application_id' => (int) $locked->application_id,
                'action' => $action,
                'qualification_id' => (int) $locked->id,
            ];
        });

        if (! (bool) ($result['changed'] ?? false)) {
            return;
        }

        $applicationId = (int) ($result['application_id'] ?? 0);
        $application = $applicationId > 0 ? Application::query()->find($applicationId) : null;

        $action = (string) ($result['action'] ?? 'fallback_level1');

        $auditMessage = match ($action) {
            'issue_certificate' => 'Qualification auto-verified and queued for certificate issuance.',
            'pending_level2' => 'Qualification auto-verified (or possible match) and routed to Level 2 review.',
            default => 'Auto-verification completed; qualification routed to Level 1 assignment pool.',
        };

        $audit->record(
            eventType: 'auto_verification.processed',
            module: 'Verification',
            actionName: 'auto_verification_processed',
            message: $auditMessage,
            entityType: Qualification::class,
            entityId: (int) ($result['qualification_id'] ?? $qualification->id),
            metadata: [
                'application_id' => $applicationId,
                'qualification_id' => (int) ($result['qualification_id'] ?? $qualification->id),
                'action' => $action,
                'threshold' => $threshold,
                'auto_issue_enabled' => $autoIssueEnabled,
            ],
            actor: null,
        );

        if ($action === 'issue_certificate') {
            $this->issueCertificateIfPossible(
                qualificationId: (int) ($result['qualification_id'] ?? $qualification->id),
                certificates: $certificates,
            );
        }

        if ($application) {
            $lifecycle->event(
                application: $application,
                eventType: 'auto_verification',
                eventCodeBase: 'auto_verification.processed.q'.(int) ($result['qualification_id'] ?? $qualification->id),
                stage: LifecycleStage::Review,
                title: $action === 'pending_level2' ? 'Qualification auto-verified' : 'Qualification queued for verification',
                description: $auditMessage,
                visibility: LifecycleVisibility::Both,
                actor: null,
                comment: null,
                metadata: [
                    'qualification_id' => (int) ($result['qualification_id'] ?? $qualification->id),
                    'action' => $action,
                ],
                occurredAt: now(),
            );
        }
    }

    private function issueCertificateIfPossible(int $qualificationId, QualificationCertificateService $certificates): void
    {
        $qualification = Qualification::query()
            ->with(['application'])
            ->find($qualificationId);

        if (! $qualification) {
            return;
        }

        $hasIssued = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->exists();

        if ($hasIssued) {
            return;
        }

        $issuer = $this->resolveAutoIssuerUser();
        if (! $issuer) {
            // Safe fallback: leave task for Level 2 rather than failing the job.
            DB::transaction(function () use ($qualification) {
                $locked = Qualification::query()->lockForUpdate()->findOrFail($qualification->id);
                if ($locked->verification_state === VerificationState::ApprovedForCertificate) {
                    $locked->forceFill([
                        'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
                    ])->save();
                }
            });
            return;
        }

        try {
            $certificates->issue($qualification, $issuer, false);
        } catch (\Illuminate\Validation\ValidationException) {
            // Idempotency / eligibility guard — leave as-is for manual handling.
        } catch (\Throwable) {
            // Do not throw from the job on certificate issuance; the qualification remains actionable.
        }
    }

    private function resolveAutoIssuerUser(): ?User
    {
        $issuerId = config('auto_verification.issuer_user_id');
        if (is_numeric($issuerId) && (int) $issuerId > 0) {
            return User::query()->find((int) $issuerId);
        }

        return User::query()
            ->role('Super Admin')
            ->orderBy('id')
            ->first();
    }
}
