<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Applications\ApplicationNotificationContact;
use App\Domain\Applications\QualificationCaptureService;
use App\Domain\Certificates\QualificationCertificateService;
use App\Domain\Documents\QualificationDocumentEvidence;
use App\Domain\Payments\ApplicationPaymentSatisfaction;
use App\Domain\Verification\AutoAssignmentResult;
use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\AutoVerifiedQualificationReviewService;
use App\Domain\Verification\AwardingInstitutionCatalogStatus;
use App\Domain\Verification\QualificationTitleCatalogStatus;
use App\Domain\Verification\QualificationAutoAssignmentService;
use App\Domain\Verification\QualificationAutoVerificationRecheckService;
use App\Domain\Verification\QualificationDecisionReopenService;
use App\Domain\Verification\QualificationDecisionService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Domain\Verification\QualificationLevel2ReviewLockService;
use App\Domain\Verification\QualificationLevel2SendBackToLevel1Service;
use App\Domain\Settings\AwardingInstitutionAccreditationStatementService;
use App\Domain\Verification\QualificationSendBackService;
use App\Domain\Verification\VerificationQualificationAccess;
use App\Enums\DocumentType;
use App\Enums\VerificationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Verification\AdminUpdateVerificationQualificationRequest;
use App\Http\Requests\Admin\Verification\AssignApplicationRequest;
use App\Http\Requests\Admin\Verification\IssueQualificationCertificateRequest;
use App\Http\Requests\Admin\Verification\QualificationDecisionApproveRequest;
use App\Http\Requests\Admin\Verification\QualificationDecisionRejectRequest;
use App\Http\Requests\Admin\Verification\QualificationLevel1CompleteRequest;
use App\Http\Requests\Admin\Verification\QualificationLevel2SendBackToLevel1Request;
use App\Http\Requests\Admin\Verification\ReopenLevel2DecisionRequest;
use App\Http\Requests\Admin\Verification\RevokeQualificationAssignmentRequest;
use App\Http\Requests\Admin\Verification\SendBackRequest;
use App\Models\ApplicationComment;
use App\Models\ApplicationLifecycleEvent;
use App\Models\AuditLog;
use App\Models\CertificateSubject;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\QualificationType;
use App\Models\User;
use App\Support\Qualifications\CertificateSubjectGrade;
use App\Support\Qualifications\QualificationAwardingInstitutionFormState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AdminVerificationQualificationController extends Controller
{
    public function show(
        Request $request,
        Qualification $qualification,
        QualificationCertificateService $certificateService,
        QualificationLevel1ReviewService $level1ReviewService,
    ): Response
    {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        $qualification = $level1ReviewService->beginReviewIfAssigned($qualification, $request->user());

        $qualification->loadMissing([
            'application.applicant',
            'application.invoice',
            'application.payments',
            'country',
            'awardingInstitution.country',
            'qualificationTypeMaster',
            'assignedVerifier',
            'documents.uploadedBy',
            'consentForm.uploadedDocument',
            'consentForm.zaqaUploadedDocument',
            'assignments.assignedBy',
            'assignments.assignedTo',
            'certificates',
            'certificates.revokedBy',
            'certificates.issuedBy',
            'learnerRecord.awardingInstitution',
            'learnerRecordMatchAttempts.learnerRecord',
            'level2ReviewLockedBy',
            'level1ReviewCompletedBy',
            'returnedToLevel1By',
            'subjectResults',
        ]);

        $applicationModel = $qualification->application;
        $paymentSatisfaction = app(ApplicationPaymentSatisfaction::class);
        $paymentSatisfied = $applicationModel ? $paymentSatisfaction->isSatisfied($applicationModel) : false;

        $applicationVerificationState = $applicationModel?->verification_state;
        $applicationBlockedForCertificateIssue = $applicationVerificationState === VerificationState::Rejected
            || $applicationVerificationState === VerificationState::Closed;

        $activeCveq = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->orderByDesc('id')
            ->first();

        $applicationClosed = $applicationVerificationState === VerificationState::Closed;

        $canIssueCveq = (bool) $request->user()?->can('verification.certificate.issue')
            && $paymentSatisfied
            && ! $applicationBlockedForCertificateIssue
            && ! $activeCveq
            && (
                $qualification->verification_state === VerificationState::ApprovedForCertificate
                || (
                    $qualification->verification_state === VerificationState::CertificateIssued
                    && ! $activeCveq
                )
            );

        $canIssueRejectionCertificate = (bool) $request->user()?->can('verification.certificate.issue')
            && $paymentSatisfied
            && ! $applicationClosed
            && ! $activeCveq
            && $qualification->verification_state === VerificationState::Rejected;

        $canReissueCveq = (bool) $request->user()?->hasRole('Super Admin')
            && (bool) $request->user()?->can('verification.certificate.issue')
            && $paymentSatisfied
            && $qualification->verification_state === VerificationState::CertificateIssued
            && ! $applicationBlockedForCertificateIssue
            && $activeCveq instanceof QualificationCertificate
            && $activeCveq->isVerificationCertificate();

        $level1Users = User::query()
            ->whereNull('applicant_type', 'and', false)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Verification Officer Level 1'))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();

        $sendBackTimeline = $this->buildSendBackResubmissionTimeline($qualification);

        $locks = app(QualificationLevel2ReviewLockService::class);
        $lockExpired = $locks->isExpired($qualification->level2_review_locked_at);
        $isLocked = (bool) $qualification->level2_review_locked_by && ! $lockExpired;
        $lockExpiresAt = $qualification->level2_review_locked_at
            ? $qualification->level2_review_locked_at->copy()->addMinutes($locks->ttlMinutes())
            : null;
        $canUseRetryActions = (bool) $request->user()?->can('verification.level2.review') || (bool) $request->user()?->hasRole('Super Admin');
        $recheckDisabledReason = $canUseRetryActions
            ? $this->recheckAutoVerificationDisabledReason($qualification, $paymentSatisfied)
            : 'You are not authorized to recheck auto-verification.';
        $autoAssignDisabledReason = $canUseRetryActions
            ? $this->autoAssignLevel1DisabledReason($qualification, $paymentSatisfied)
            : 'You are not authorized to auto-assign this qualification.';
        $canViewLearnerRecords = (bool) $request->user()?->can('learner_records.view');
        $learnerRecordsUrl = $canViewLearnerRecords && $qualification->awarding_institution_id
            ? route('admin.learner_records.index', ['awarding_institution_id' => $qualification->awarding_institution_id])
            : null;
        $learnerRecordsDisabledReason = $canViewLearnerRecords && ! $qualification->awarding_institution_id
            ? 'No linked awarding institution for this qualification.'
            : null;
        $certificateTemplate = $certificateService->describeTemplate($qualification);
        $canRevokeCertificate = (bool) $request->user()?->can('certificates.revoke');
        $canReopenLevel2Decision = (bool) $request->user()?->can('verification.decision.reopen')
            && app(QualificationDecisionReopenService::class)->canReopen($qualification);

        $decisionReopenEvents = $applicationModel
            ? ApplicationLifecycleEvent::query()
                ->where('application_id', $applicationModel->id)
                ->where('event_code', 'like', 'verification.level2_decision_reopened.q'.$qualification->id.'.%')
                ->orderByDesc('occurred_at')
                ->limit(5)
                ->get()
                ->map(fn (ApplicationLifecycleEvent $event) => [
                    'occurred_at' => optional($event->occurred_at)?->toIso8601String(),
                    'actor_name' => $event->actor_name_snapshot,
                    'reason' => $event->comment,
                    'intended_action' => is_array($event->metadata) ? ($event->metadata['intended_action'] ?? null) : null,
                    'previous_qualification_state' => is_array($event->metadata) ? ($event->metadata['previous_qualification_state'] ?? null) : null,
                ])
                ->values()
                ->all()
            : [];
        $certificateHistory = $this->buildCertificateHistoryPayload(
            $qualification->certificates->sortByDesc('id')->values(),
            $canRevokeCertificate,
        );
        $titleCatalog = app(QualificationTitleCatalogStatus::class)->forQualification($qualification);
        $institutionCatalog = app(AwardingInstitutionCatalogStatus::class)->forQualification($qualification);
        $qualificationServiceStartedAt = $qualification->service_started_at
            ?? $qualification->application?->submitted_at
            ?? $qualification->application?->created_at;
        $qualificationServiceDeadlineAt = $qualification->service_deadline_at
            ?? $qualification->application?->service_deadline_at;

        $qualificationTypes = QualificationType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'zqf_level_code', 'level_label', 'name']);

        if (
            $qualification->qualification_type_id
            && ! $qualificationTypes->contains('id', (int) $qualification->qualification_type_id)
        ) {
            $currentType = QualificationType::query()->find($qualification->qualification_type_id);
            if ($currentType) {
                $qualificationTypes->push($currentType);
                $qualificationTypes = $qualificationTypes->sortBy('sort_order')->values();
            }
        }

        return Inertia::render('Admin/Verification/Qualifications/Show', [
            'qualification' => [
                'id' => $qualification->id,
                'verification_reference_number' => $qualification->verification_reference_number,
                'verification_state' => $qualification->verification_state?->value
                    ?? VerificationState::AwaitingAssignment->value,
                'is_foreign' => (bool) $qualification->is_foreign_qualification,
                'assigned_verifier_id' => $qualification->assigned_verifier_id,
                'assigned_verifier_name' => $qualification->assignedVerifier?->name,
                'assigned_at' => optional($qualification->assigned_at)?->toIso8601String(),
                'returned_to_applicant_at' => optional($qualification->returned_to_applicant_at)?->toIso8601String(),
                'reviewed_at' => optional($qualification->reviewed_at)?->toIso8601String(),
                'service_started_at' => optional($qualificationServiceStartedAt)?->toIso8601String(),
                'service_deadline_at' => optional($qualificationServiceDeadlineAt)?->toIso8601String(),
                'reviewer_notes' => $qualification->reviewer_notes,
                'level1_review' => $this->buildLevel1ReviewPayload($qualification),
                'awarding_institution_accreditation_statement' => $this->awardingInstitutionAccreditationStatement($qualification),
                'awarding_institution_has_accreditation_statement' => $this->awardingInstitutionHasAccreditationStatement($qualification),
                'level1_correction_cycle' => (int) ($qualification->level1_correction_cycle ?? 0),
                'level1_corrections_received' => (int) ($qualification->level1_correction_cycle ?? 0) > 0
                    && $qualification->returned_to_level1_at === null
                    && $qualification->reviewed_at !== null
                    && in_array($qualification->verification_state, [VerificationState::UnderLevel2Review, VerificationState::AutoVerifiedPendingLevel2], true),
                'level2_send_back_correction' => $this->buildActiveLevel2SendBackCorrection($qualification),
                'level2_send_back_history' => $this->buildLevel2SendBackHistory($qualification),
                'send_back_to_level1_url' => route('admin.verification.qualifications.send_back_to_level1', ['qualification' => $qualification]),
                'fee_currency' => $qualification->fee_currency,
                'fee_amount_cents' => $qualification->fee_amount_cents,
                'application' => [
                    'id' => $qualification->application?->id,
                    'application_number' => $qualification->application?->application_number,
                    'current_status' => $qualification->application?->current_status?->value ?? (string) $qualification->application?->current_status,
                    'verification_state' => $qualification->application?->verification_state?->value ?? (string) ($qualification->application?->verification_state ?? ''),
                    'payment_status' => $qualification->application?->paid_at ? 'paid' : 'unpaid',
                    'payment_satisfied' => $paymentSatisfied,
                    'submitted_at' => optional($qualification->application?->submitted_at)?->toIso8601String(),
                    'created_at' => optional($qualification->application?->created_at)?->toIso8601String(),
                    'service_deadline_at' => optional($qualification->application?->service_deadline_at)?->toIso8601String(),
                    'completed_at' => optional($qualification->application?->completed_at)?->toIso8601String(),
                    'applicant_name' => $qualification->application?->metadata['verification_subject']['full_name'] ?? $qualification->application?->applicant?->name,
                    'notification_contact_label' => $qualification->application instanceof \App\Models\Application
                        ? ApplicationNotificationContact::adminLabel($qualification->application)
                        : 'Applicant account',
                ],
                'cveq_certificate' => $activeCveq
                    ? [
                        'certificate_number' => $activeCveq->certificate_number,
                        'issued_at' => optional($activeCveq->issued_at)?->toIso8601String(),
                        'admin_download_url' => route('admin.verification.qualifications.certificate.download', ['qualification' => $qualification]),
                    ]
                    : null,
                'issue_certificate_url' => route('admin.verification.qualifications.issue_certificate', ['qualification' => $qualification]),
                'issue_rejection_certificate_url' => route('admin.verification.qualifications.issue_rejection_certificate', ['qualification' => $qualification]),
                'can_issue_cveq_certificate' => $canIssueCveq,
                'can_issue_rejection_certificate' => $canIssueRejectionCertificate,
                'can_reissue_cveq_certificate' => $canReissueCveq,
                'can_reopen_level2_decision' => $canReopenLevel2Decision,
                'reopen_level2_decision_url' => route('admin.verification.qualifications.reopen_level2_decision', ['qualification' => $qualification]),
                'decision_reopen_history' => $decisionReopenEvents,
                'reopen_intended_actions' => [
                    ['value' => QualificationDecisionReopenService::INTENDED_RECONSIDER_APPROVAL, 'label' => 'Reconsider for approval'],
                    ['value' => QualificationDecisionReopenService::INTENDED_RECONSIDER_REJECTION, 'label' => 'Reconsider for rejection'],
                    ['value' => QualificationDecisionReopenService::INTENDED_FURTHER_REVIEW, 'label' => 'Reopen for further review'],
                ],
                'certificate_history' => $certificateHistory,
                'certificate_template' => $certificateTemplate,
                'qualification_type' => $qualification->qualificationTypeMaster?->name,
                'qualification_type_id' => $qualification->qualification_type_id,
                'title' => $qualification->title_of_qualification,
                'names_as_on_qualification_document' => $qualification->names_as_on_qualification_document,
                'applicant_entered_qualification_title' => $qualification->applicant_entered_qualification_title,
                'verified_qualification_title' => $qualification->verified_qualification_title,
                'qualification_title_source' => $qualification->qualification_title_source?->value ?? (string) ($qualification->qualification_title_source ?? ''),
                'title_catalog' => $titleCatalog,
                'institution_catalog' => $institutionCatalog,
                'awarding_institution' => $qualification->awardingInstitution?->name ?? $qualification->awarding_institution_name_other ?? $qualification->awarding_institution_name,
                'awarding_institution_id' => $qualification->awarding_institution_id,
                'country' => $qualification->country?->name ?? $qualification->country_name_other,
                'holder_name' => $qualification->qualification_holder_name,
                'holder_nrc_passport' => $qualification->nrc_passport_number,
                'student_number' => $qualification->student_number,
                'certificate_number' => $qualification->certificate_number,
                'award_date' => optional($qualification->award_date)?->format('Y-m-d'),
                'subject_results' => $qualification->subjectResults
                    ->sortBy(fn ($row) => [$row->display_order ?? PHP_INT_MAX, $row->id])
                    ->values()
                    ->map(fn ($row, int $index) => [
                        'id' => $row->id,
                        'display_order' => $row->display_order,
                        'index' => $index + 1,
                        'subject_name' => $row->subject_name,
                        'grade' => $row->grade,
                    ]),
                'auto_verification' => [
                    'attempted_at' => optional($qualification->auto_verification_attempted_at)?->toIso8601String(),
                    'status' => $qualification->auto_verification_status?->value ?? (string) ($qualification->auto_verification_status ?? ''),
                    'confidence' => $qualification->auto_verification_confidence !== null ? min(100, (int) $qualification->auto_verification_confidence) : null,
                    'failure_reason' => $qualification->auto_verification_failure_reason,
                    'match_summary' => $qualification->auto_verification_match_summary,
                    'source' => $qualification->verification_source,
                    'auto_verified_at' => optional($qualification->auto_verified_at)?->toIso8601String(),
                ],
                'level2_review_lock' => [
                    'is_locked' => $isLocked,
                    'locked_by_user_id' => $isLocked ? (int) $qualification->level2_review_locked_by : null,
                    'locked_by_name' => $isLocked ? ($qualification->level2ReviewLockedBy?->name ?? null) : null,
                    'locked_at' => $isLocked ? optional($qualification->level2_review_locked_at)?->toIso8601String() : null,
                    'expires_at' => $isLocked ? optional($lockExpiresAt)?->toIso8601String() : null,
                ],
                'level2_lock_url' => route('admin.verification.qualifications.level2_lock', ['qualification' => $qualification]),
                'level2_unlock_url' => route('admin.verification.qualifications.level2_unlock', ['qualification' => $qualification]),
                'send_to_manual_review_url' => route('admin.verification.qualifications.send_to_manual_review', ['qualification' => $qualification]),
                'recheck_auto_verification_url' => route('admin.verification.qualifications.recheck_auto_verification', ['qualification' => $qualification]),
                'auto_assign_level1_url' => route('admin.verification.qualifications.auto_assign_level1', ['qualification' => $qualification]),
                'recheck_auto_verification_enabled' => $recheckDisabledReason === null,
                'recheck_auto_verification_disabled_reason' => $recheckDisabledReason,
                'auto_assign_level1_enabled' => $autoAssignDisabledReason === null,
                'auto_assign_level1_disabled_reason' => $autoAssignDisabledReason,
                'learner_records_url' => $learnerRecordsUrl,
                'learner_records_disabled_reason' => $learnerRecordsDisabledReason,
                'learner_record' => $qualification->learnerRecord
                    ? [
                        'id' => $qualification->learnerRecord->id,
                        'awarding_institution' => $qualification->learnerRecord->awardingInstitution
                            ? [
                                'id' => $qualification->learnerRecord->awardingInstitution->id,
                                'name' => $qualification->learnerRecord->awardingInstitution->name,
                            ]
                            : null,
                        'institution_name_raw' => $qualification->learnerRecord->institution_name_raw,
                        'student_id' => $qualification->learnerRecord->student_id,
                        'certificate_no' => $qualification->learnerRecord->certificate_no,
                        'nrc_number' => $qualification->learnerRecord->nrc_number,
                        'passport_no' => $qualification->learnerRecord->passport_no,
                        'program_of_study' => $qualification->learnerRecord->program_of_study,
                        'year_awarded' => $qualification->learnerRecord->year_awarded,
                        'award_date' => optional($qualification->learnerRecord->award_date)?->format('Y-m-d'),
                        'source_type' => $qualification->learnerRecord->source_type?->value,
                    ]
                    : null,
                'match_attempts' => $qualification->learnerRecordMatchAttempts
                    ->sortByDesc('id')
                    ->take(20)
                    ->values()
                    ->map(fn ($a) => [
                        'id' => $a->id,
                        'status' => $a->status?->value ?? (string) ($a->status ?? ''),
                        'confidence' => $a->confidence !== null ? min(100, (int) $a->confidence) : null,
                        'source' => $a->source,
                        'matched_fields' => $a->matched_fields,
                        'candidate_record_ids' => $a->candidate_record_ids,
                        'failure_reason' => $a->failure_reason,
                        'learner_record_id' => $a->learner_record_id,
                        'created_at' => optional($a->created_at)?->toIso8601String(),
                    ]),
                'documents' => QualificationDocumentEvidence::filterOfficerApplicantEvidence($qualification->documents)
                    ->map(fn ($d) => [
                        'id' => $d->id,
                        'document_type' => $d->document_type?->value ?? (string) $d->document_type,
                        'original_name' => $d->original_name,
                        'uploaded_by' => $d->uploadedBy?->name,
                        'created_at' => optional($d->created_at)?->toIso8601String(),
                        'preview_url' => route('admin.verification.documents.preview', ['document' => $d->id]),
                        'download_url' => route('admin.verification.documents.download', ['document' => $d->id]),
                    ])
                    ->values()
                    ->all(),
                'consent' => $qualification->consentForm
                    ? [
                        'id' => $qualification->consentForm->id,
                        'consent_type' => $qualification->consentForm->consent_type?->value ?? (string) $qualification->consentForm->consent_type,
                        'agreed_at' => optional($qualification->consentForm->agreed_at)?->toIso8601String(),
                        'uploaded_document_id' => $qualification->consentForm->uploaded_document_id,
                        'zaqa_uploaded_document_id' => $qualification->consentForm->zaqa_uploaded_document_id,
                    ]
                    : null,
                'assignments' => $qualification->assignments
                    ->sortByDesc('assigned_at')
                    ->values()
                    ->map(fn ($a) => [
                        'id' => $a->id,
                        'assigned_by' => $a->assignedBy?->name,
                        'assigned_to' => $a->assignedTo?->name,
                        'comment' => $a->comment,
                        'assigned_at' => optional($a->assigned_at)?->toIso8601String(),
                        'unassigned_at' => optional($a->unassigned_at)?->toIso8601String(),
                    ]),
            ],
            'send_back_timeline' => $sendBackTimeline,
            'viewerUserId' => $request->user()?->id,
            'level1Users' => $level1Users,
            'qualificationTypes' => $qualificationTypes->map(fn (QualificationType $t) => [
                'id' => $t->id,
                'zqf_level_code' => $t->zqf_level_code,
                'level_label' => $t->level_label,
                'name' => $t->name,
            ])->values()->all(),
            'can' => [
                'assign' => (bool) $request->user()?->can('verification.assign'),
                'send_back' => (bool) $request->user()?->can('verification.send_back'),
                'level1_process' => (bool) $request->user()?->can('verification.level1.process'),
                'level2_review' => (bool) $request->user()?->can('verification.level2.review'),
                'send_back_to_level1' => $this->canSendBackToLevel1($qualification, $request->user()),
                'approve' => (bool) $request->user()?->can('verification.decide.approve'),
                'reject' => (bool) $request->user()?->can('verification.decide.reject'),
                'edit_qualification' => (bool) ($request->user()?->can('verification.level1.process') || $request->user()?->can('verification.level2.review')),
                'issue_certificate' => (bool) $request->user()?->can('verification.certificate.issue'),
                'revoke_certificate' => $canRevokeCertificate,
                'reopen_decision' => (bool) $request->user()?->can('verification.decision.reopen'),
                'is_super_admin' => (bool) $request->user()?->hasRole('Super Admin'),
                'view_learner_records' => $canViewLearnerRecords,
            ],
        ]);
    }

    public function reopenLevel2Decision(
        ReopenLevel2DecisionRequest $request,
        Qualification $qualification,
        QualificationDecisionReopenService $reopen,
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        $reopen->reopen(
            $qualification,
            $request->user(),
            (string) $request->validated('reason'),
            (string) $request->validated('intended_action'),
        );

        return back()->with('success', 'Level 2 decision reopened. You may now record a new approval or rejection.');
    }

    public function issueCertificate(
        IssueQualificationCertificateRequest $request,
        Qualification $qualification,
        QualificationCertificateService $certificates,
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        $certificates->issue(
            $qualification,
            $request->user(),
            $request->boolean('reissue'),
        );

        return back()->with('success', 'Certificate issued successfully.');
    }

    public function issueRejectionCertificate(
        IssueQualificationCertificateRequest $request,
        Qualification $qualification,
        QualificationCertificateService $certificates,
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        $certificates->issueRejection($qualification, $request->user());

        return back()->with('success', 'Rejection notice issued successfully.');
    }

    public function downloadCertificate(
        Request $request,
        Qualification $qualification,
        QualificationCertificateService $certificates,
    ): SymfonyResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        $record = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->orderByDesc('id')
            ->first();

        abort_unless($record instanceof QualificationCertificate, 404);

        return response($certificates->pdfContents($record))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="ZAQA-'.$record->certificate_number.'.pdf"');
    }

    public function edit(
        Request $request,
        Qualification $qualification,
        QualificationLevel1ReviewService $level1ReviewService,
    ): Response
    {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);
        abort_unless(
            $request->user()->can('verification.level1.process') || $request->user()->can('verification.level2.review'),
            403
        );

        $qualification = $level1ReviewService->beginReviewIfAssigned($qualification, $request->user());

        $user = $request->user();
        if ($user && VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
            $vs = $qualification->verification_state;
            $allowed = [VerificationState::AssignedToLevel1, VerificationState::UnderLevel1Review];
            abort_unless($vs instanceof VerificationState && in_array($vs, $allowed, true), 403);
        }

        $qualification->load([
            'subjectResults',
            'country',
            'awardingInstitution',
            'qualificationTypeMaster',
            'application.applicant.applicantProfile',
            'application.documents.uploadedBy',
            'documents.uploadedBy',
            'level1ReviewCompletedBy',
            'level2ReviewLockedBy',
        ]);

        $decisionContext = $this->qualificationEditDecisionContext($qualification);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'iso_code', 'name'])
            ->map(fn (Country $c) => ['id' => $c->id, 'iso_code' => $c->iso_code, 'name' => $c->name])
            ->all();

        $qualificationTypes = QualificationType::query()
            ->with('billingCategory')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $certificateSubjects = CertificateSubject::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (CertificateSubject $s) => ['id' => $s->id, 'name' => $s->name])
            ->all();

        $institutionForm = QualificationAwardingInstitutionFormState::forForm($qualification);

        return Inertia::render('Admin/Verification/Qualifications/Edit', [
            'qualification' => array_merge([
                'id' => $qualification->id,
                'qualification_holder_name' => $qualification->qualification_holder_name,
                'names_as_on_qualification_document' => $qualification->names_as_on_qualification_document,
                'nrc_passport_number' => $qualification->nrc_passport_number,
                'country_id' => $qualification->country_id,
                'country_name_other' => $qualification->country_name_other,
                'awarding_institution_id' => $institutionForm['awarding_institution_id'],
                'awarding_institution_name_other' => $institutionForm['awarding_institution_name_other'],
                'awarding_institution_name' => $institutionForm['awarding_institution_name'],
                'certificate_number' => $qualification->certificate_number,
                'student_number' => $qualification->student_number,
                'examination_number' => $qualification->examination_number,
                'title_of_qualification' => $qualification->title_of_qualification,
                'award_date' => $qualification->award_date?->format('Y-m-d'),
                'qualification_type_id' => $qualification->qualification_type_id,
                'is_foreign_qualification' => (bool) $qualification->is_foreign_qualification,
                'transcript_required' => (bool) $qualification->transcript_required,
                'subject_results' => $qualification->subjectResults->map(function ($row) {
                    $subjectId = $row->certificate_subject_id
                        ? (int) $row->certificate_subject_id
                        : CertificateSubject::resolveIdByName($row->subject_name);

                    return [
                        'certificate_subject_id' => $subjectId,
                        'subject_name' => $row->subject_name,
                        'grade' => $row->grade,
                    ];
                })->values()->all(),
            ], $decisionContext),
            'application' => [
                'id' => $qualification->application_id,
                'application_number' => $qualification->application?->application_number,
                'payment_satisfied' => $decisionContext['application']['payment_satisfied'] ?? false,
            ],
            'viewerUserId' => $user?->id,
            'can' => $this->qualificationOfficerPermissions($user),
            'countries' => $countries,
            'qualificationTypes' => $qualificationTypes->map(fn (QualificationType $t) => [
                'id' => $t->id,
                'zqf_level_code' => $t->zqf_level_code,
                'level_label' => $t->level_label,
                'name' => $t->name,
                'requires_subject_results' => (bool) $t->requires_subject_results,
            ])->values()->all(),
            'certificateSubjects' => $certificateSubjects,
            'subjectGradeOptions' => CertificateSubjectGrade::allowed(),
            'documents' => $this->qualificationEditDocumentsPayload($qualification),
            'expected_document_types' => $this->qualificationEditExpectedDocumentTypes($qualification),
            'identity_document' => $this->qualificationEditIdentityPayload($qualification),
            'correction_history' => $this->qualificationCorrectionHistory($qualification),
        ]);
    }

    public function update(
        AdminUpdateVerificationQualificationRequest $request,
        Qualification $qualification,
        QualificationCaptureService $capture,
    ): RedirectResponse {
        $user = $request->user();
        if ($user && VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
            $vs = $qualification->verification_state;
            $allowed = [VerificationState::AssignedToLevel1, VerificationState::UnderLevel1Review];
            abort_unless($vs instanceof VerificationState && in_array($vs, $allowed, true), 403);
        }

        $validated = $request->validated();
        if (! $capture->adminVerificationCorrectionWouldChange($qualification, $validated)) {
            return redirect()
                ->route('admin.verification.qualifications.edit', $qualification)
                ->with('info', 'No changes to save.');
        }

        $capture->adminVerificationCorrection($qualification, $validated, $request->user());

        return redirect()
            ->route('admin.verification.qualifications.show', $qualification)
            ->with('success', 'Qualification details updated.');
    }

    public function assign(AssignApplicationRequest $request, Qualification $qualification, AssignmentService $assignments): RedirectResponse
    {
        /** @var User $assignee */
        $assignee = User::query()->findOrFail((int) $request->validated('assigned_to_user_id'));

        $assignments->assign($qualification, $request->user(), $assignee, $request->validated('comment'));

        return back()->with('success', 'Assigned to verifier.');
    }

    public function revokeAssignment(RevokeQualificationAssignmentRequest $request, Qualification $qualification, AssignmentService $assignments): RedirectResponse
    {
        $assignments->revokeLevel1Assignment(
            $qualification,
            $request->user(),
            $request->validated('comment'),
        );

        return back()->with('success', 'Level 1 assignment removed. This task is awaiting assignment again.');
    }

    public function sendBack(SendBackRequest $request, Qualification $qualification, QualificationSendBackService $sendBack): RedirectResponse
    {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        if ($qualification->verification_state === VerificationState::AutoVerifiedPendingLevel2) {
            app(QualificationLevel2ReviewLockService::class)->assertActorHoldsLockOrIsSuperAdmin($qualification, $request->user());
        }

        $sendBack->sendBackToApplicant($qualification, $request->user(), (string) $request->validated('comment'));

        // Send-back clears Level 1 assignment; restricted verifiers would get 403 on the same qualification URL.
        $message = 'Qualification sent back to applicant.';
        $user = $request->user();
        if ($user && VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
            return redirect()
                ->route('admin.verification.assigned_to_me')
                ->with('success', $message);
        }

        return redirect()
            ->route('admin.verification.pool.index')
            ->with('success', $message);
    }

    public function sendBackToLevel1(
        QualificationLevel2SendBackToLevel1Request $request,
        Qualification $qualification,
        QualificationLevel2SendBackToLevel1Service $sendBack,
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        if ($qualification->verification_state === VerificationState::AutoVerifiedPendingLevel2) {
            app(QualificationLevel2ReviewLockService::class)->assertActorHoldsLockOrIsSuperAdmin($qualification, $request->user());
        }

        $sendBack->sendBackToLevel1(
            $qualification,
            $request->user(),
            (string) $request->validated('comment'),
            $request->file('attachment'),
        );

        return back()->with('success', 'Qualification sent back to Level 1 for correction.');
    }

    public function level1Complete(QualificationLevel1CompleteRequest $request, Qualification $qualification, QualificationLevel1ReviewService $reviews): RedirectResponse
    {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        $reviews->completeLevel1(
            $qualification,
            $request->user(),
            (string) $request->validated('findings'),
            $request->boolean('recommended_for_award'),
            (int) $request->validated('qualification_type_id'),
            $request->validated('accreditation_statement'),
            $request->file('attachment'),
            $request->file('evaluation_report'),
        );

        return back()->with('success', 'Level 1 review completed for this qualification.');
    }

    public function approve(
        QualificationDecisionApproveRequest $request,
        Qualification $qualification,
        QualificationDecisionService $decisions,
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        $issueCertificate = $request->boolean('issue_certificate');
        $decisions->approve(
            $qualification,
            $request->user(),
            $request->validated('comment'),
            $issueCertificate,
            (string) $request->validated('findings'),
            $request->validated('accreditation_statement'),
        );

        return back()->with(
            'success',
            $issueCertificate ? 'Qualification approved and certificate issued.' : 'Qualification approved.',
        );
    }

    public function reject(
        QualificationDecisionRejectRequest $request,
        Qualification $qualification,
        QualificationDecisionService $decisions,
        QualificationCertificateService $certificates,
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        $decisions->reject(
            $qualification,
            $request->user(),
            (string) $request->validated('reason'),
            (string) $request->validated('findings'),
            $request->validated('accreditation_statement'),
        );

        if ($request->boolean('generate_rejection_notice') && $request->user()?->can('verification.certificate.issue')) {
            try {
                $certificates->issueRejection($qualification->fresh(), $request->user());

                return back()->with('success', 'Qualification rejected and rejection notice issued.');
            } catch (\Throwable $exception) {
                report($exception);

                return back()->with(
                    'success',
                    'Qualification rejected. The rejection notice could not be generated automatically — issue it manually from the certificate panel.',
                );
            }
        }

        return back()->with('success', 'Qualification rejected.');
    }

    public function lockForLevel2Review(
        Request $request,
        Qualification $qualification,
        QualificationLevel2ReviewLockService $locks,
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        abort_unless((bool) $request->user()?->can('verification.level2.review'), 403);

        if ($qualification->verification_state !== VerificationState::AutoVerifiedPendingLevel2) {
            return back()->withErrors([
                'lock' => 'This qualification is not in the auto-verified Level 2 review queue.',
            ]);
        }

        try {
            $locks->lock($qualification, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Locked for Level 2 review.');
    }

    public function unlockLevel2Review(
        Request $request,
        Qualification $qualification,
        QualificationLevel2ReviewLockService $locks,
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        abort_unless((bool) $request->user()?->can('verification.level2.review'), 403);

        try {
            $locks->unlock($qualification, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Review lock released.');
    }

    public function sendToManualReview(
        Request $request,
        Qualification $qualification,
        AutoVerifiedQualificationReviewService $autoVerified,
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);
        abort_unless((bool) $request->user()?->can('verification.level2.review'), 403);

        $autoVerified->sendToManualReview($qualification, $request->user());

        return redirect()
            ->route('admin.verification.auto_verified.index')
            ->with('success', 'Qualification sent to manual review queue.');
    }

    public function recheckAutoVerification(
        Request $request,
        Qualification $qualification,
        QualificationAutoVerificationRecheckService $rechecks,
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);
        abort_unless((bool) $request->user()?->can('verification.level2.review'), 403);

        $paymentSatisfied = $qualification->application
            ? app(ApplicationPaymentSatisfaction::class)->isSatisfied($qualification->application)
            : false;
        $blockedReason = $this->recheckAutoVerificationDisabledReason($qualification, $paymentSatisfied);
        if ($blockedReason !== null) {
            return back()->with('error', $blockedReason);
        }

        $result = $rechecks->queue($qualification, $request->user());

        return back()->with($result->queued ? 'success' : 'error', $result->message);
    }

    public function autoAssignLevel1(
        Request $request,
        Qualification $qualification,
        QualificationAutoAssignmentService $autoAssignments,
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);
        abort_unless((bool) $request->user()?->can('verification.level2.review'), 403);

        $qualification->loadMissing(['application', 'assignedVerifier']);

        $paymentSatisfied = $qualification->application
            ? app(ApplicationPaymentSatisfaction::class)->isSatisfied($qualification->application)
            : false;
        $blockedReason = $this->autoAssignLevel1DisabledReason($qualification, $paymentSatisfied);
        if ($blockedReason !== null) {
            return back()->with('error', $blockedReason);
        }

        $beforeState = $qualification->only([
            'verification_state',
            'assigned_verifier_id',
            'verification_assignment_category_id',
            'assignment_source',
            'assignment_failure_reason',
            'auto_assigned_at',
        ]);

        $result = $autoAssignments->autoAssign(
            $qualification,
            actor: $request->user(),
            reason: 'manual_retry_from_admin',
        );

        $qualification->refresh()->loadMissing(['application', 'assignedVerifier']);

        $message = $this->autoAssignFeedbackMessage($qualification, $request->user(), $result);

        if ($qualification->application) {
            ApplicationComment::query()->create([
                'application_id' => (int) $qualification->application_id,
                'qualification_id' => (int) $qualification->id,
                'author_user_id' => (int) $request->user()->id,
                'type' => 'assignment_note',
                'visibility' => 'internal',
                'body' => $message,
            ]);
        }

        app(\App\Domain\Audit\AuditLogService::class)->record(
            eventType: 'verification.auto_assignment_retry',
            module: 'Verification',
            actionName: 'auto_assignment_retry',
            message: $message,
            entityType: Qualification::class,
            entityId: (int) $qualification->id,
            beforeState: $beforeState,
            afterState: $qualification->only([
                'verification_state',
                'assigned_verifier_id',
                'verification_assignment_category_id',
                'assignment_source',
                'assignment_failure_reason',
                'auto_assigned_at',
            ]),
            metadata: [
                'application_id' => (int) $qualification->application_id,
                'qualification_id' => (int) $qualification->id,
                'previous_verification_state' => $beforeState['verification_state'] ?? null,
                'previous_assigned_verifier_id' => $beforeState['assigned_verifier_id'] ?? null,
                'previous_assignment_failure_reason' => $beforeState['assignment_failure_reason'] ?? null,
                'assigned' => $result->assigned,
                'already_assigned' => $result->alreadyAssigned,
                'assignee_user_id' => $result->assigneeUserId,
                'category_id' => $result->categoryId,
                'failure_reason' => $result->failureReason,
            ],
            actor: $request->user(),
        );

        return back()->with($result->assigned ? 'success' : 'error', $message);
    }

    /**
     * @return list<string>
     */
    private function qualificationEditExpectedDocumentTypes(Qualification $qualification): array
    {
        $types = [DocumentType::CertificateCopy->value];

        if ($qualification->transcript_required) {
            $types[] = DocumentType::Transcript->value;
        }

        if ($qualification->is_foreign_qualification) {
            $types[] = DocumentType::ConsentFormSigned->value;
        }

        foreach (QualificationDocumentEvidence::filterActiveEvidence($qualification->documents) as $document) {
            $type = $document->document_type?->value ?? (string) $document->document_type;
            if ($type !== '' && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function qualificationEditDocumentsPayload(Qualification $qualification): array
    {
        return $qualification->documents
            ->pipe(fn ($docs) => QualificationDocumentEvidence::filterActiveEvidence($docs))
            ->sortByDesc('id')
            ->values()
            ->map(fn ($d) => $this->mapEditDocumentRow($d))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function qualificationEditIdentityPayload(Qualification $qualification): ?array
    {
        $application = $qualification->application;
        if (! $application) {
            return null;
        }

        $appIdentity = $application->documents
            ->filter(fn ($d) => QualificationDocumentEvidence::isActiveEvidence($d))
            ->filter(fn ($d) => in_array($d->document_type, [DocumentType::NrcCopy, DocumentType::PassportCopy], true)
                && $d->qualification_id === null
                && $d->is_current_version)
            ->sortByDesc('id')
            ->first();

        if ($appIdentity) {
            return [
                'source' => 'application',
                'document_type' => $appIdentity->document_type?->value ?? (string) $appIdentity->document_type,
                'original_name' => $appIdentity->original_name,
                'preview_url' => route('admin.verification.documents.preview', ['document' => $appIdentity->id]),
                'download_url' => route('admin.verification.documents.download', ['document' => $appIdentity->id]),
                'document_id' => $appIdentity->id,
                'can_delete' => true,
                'delete_url' => route('admin.verification.qualifications.documents.destroy', [
                    'qualification' => $qualification,
                    'document' => $appIdentity->id,
                ]),
            ];
        }

        $profile = $application->applicant?->applicantProfile;
        if ($profile?->identity_document_path) {
            $meta = $application->metadata['verification_subject'] ?? [];
            $identityType = is_array($meta) ? strtolower((string) ($meta['identity_type'] ?? 'nrc')) : 'nrc';

            return [
                'source' => 'profile',
                'document_type' => $identityType === 'passport' ? DocumentType::PassportCopy->value : DocumentType::NrcCopy->value,
                'original_name' => $profile->identity_document_original_name,
                'preview_url' => route('admin.verification.qualifications.profile_identity.preview', ['qualification' => $qualification]),
                'download_url' => route('admin.verification.qualifications.profile_identity.download', ['qualification' => $qualification]),
                'document_id' => null,
                'can_delete' => false,
            ];
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function qualificationCorrectionHistory(Qualification $qualification): array
    {
        return AuditLog::query()
            ->where('entity_type', Qualification::class)
            ->where('entity_id', $qualification->id)
            ->whereIn('event_type', [
                'verification.qualification_corrected',
                'verification.qualification_document_uploaded',
                'verification.qualification_document_deleted',
            ])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(function (AuditLog $log) {
                $metadata = (array) ($log->metadata ?? []);
                $fieldChanges = is_array($metadata['field_changes'] ?? null) ? $metadata['field_changes'] : [];
                $beforeState = (array) ($log->before_state ?? []);
                $afterState = (array) ($log->after_state ?? []);

                $summary = match ($log->event_type) {
                    'verification.qualification_document_uploaded' => isset($afterState['document_type'])
                        ? 'Uploaded '.str_replace('_', ' ', (string) $afterState['document_type'])
                        : 'Document uploaded',
                    'verification.qualification_document_deleted' => isset($beforeState['document_type'])
                        ? 'Removed '.str_replace('_', ' ', (string) $beforeState['document_type'])
                        : 'Document removed',
                    default => $fieldChanges === []
                        ? 'Saved with no field changes'
                        : count($fieldChanges).' field(s) updated',
                };

                return [
                    'id' => $log->id,
                    'event_type' => $log->event_type,
                    'at' => optional($log->created_at)?->toIso8601String(),
                    'actor_name' => $log->actor_name_snapshot,
                    'note' => $metadata['correction_note'] ?? null,
                    'summary' => $summary,
                    'field_changes' => $fieldChanges,
                    'document_before' => $beforeState['original_name'] ?? ($beforeState['document_type'] ?? null),
                    'document_after' => $afterState['original_name'] ?? ($afterState['document_type'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEditDocumentRow(\App\Models\QualificationDocument $d): array
    {
        return [
            'id' => $d->id,
            'document_type' => $d->document_type?->value ?? (string) $d->document_type,
            'original_name' => $d->original_name,
            'version_number' => $d->version_number,
            'is_current_version' => (bool) $d->is_current_version,
            'qualification_id' => $d->qualification_id,
            'uploaded_by' => $d->uploadedBy?->name,
            'created_at' => optional($d->created_at)?->toIso8601String(),
            'preview_url' => route('admin.verification.documents.preview', ['document' => $d->id]),
            'download_url' => route('admin.verification.documents.download', ['document' => $d->id]),
            'can_delete' => ! in_array($d->document_type, [
                DocumentType::PaymentProof,
                DocumentType::GeneratedReceipt,
                DocumentType::GeneratedCertificate,
                DocumentType::Level1ReviewAttachment,
            ], true),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildActiveLevel2SendBackCorrection(Qualification $qualification): ?array
    {
        if ($qualification->returned_to_level1_at === null) {
            return null;
        }

        $vs = $qualification->verification_state;
        if (! $vs instanceof VerificationState || ! in_array($vs, [VerificationState::AssignedToLevel1, VerificationState::UnderLevel1Review, VerificationState::AwaitingAssignment], true)) {
            return null;
        }

        $comment = ApplicationComment::query()
            ->where('qualification_id', $qualification->id)
            ->where('type', 'level2_send_back_to_level1')
            ->orderByDesc('created_at')
            ->first();

        $documents = $qualification->relationLoaded('documents')
            ? $qualification->documents
            : $qualification->documents()->with('uploadedBy')->get();

        $attachment = $documents
            ->where('document_type', DocumentType::Level2SendBackToLevel1Attachment)
            ->where('is_current_version', true)
            ->sortByDesc('id')
            ->first();

        return [
            'comment' => $comment?->body,
            'sent_at' => optional($qualification->returned_to_level1_at)?->toIso8601String(),
            'sent_by_name' => $qualification->returnedToLevel1By?->name,
            'correction_cycle' => (int) ($qualification->level1_correction_cycle ?? 0),
            'attachment' => $attachment ? [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'preview_url' => route('admin.verification.documents.preview', ['document' => $attachment->id]),
                'download_url' => route('admin.verification.documents.download', ['document' => $attachment->id]),
            ] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLevel2SendBackHistory(Qualification $qualification): array
    {
        return ApplicationComment::query()
            ->where('qualification_id', $qualification->id)
            ->where('type', 'level2_send_back_to_level1')
            ->with('author')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (ApplicationComment $comment) => [
                'comment' => $comment->body,
                'sent_at' => optional($comment->created_at)?->toIso8601String(),
                'sent_by_name' => $comment->author?->name,
            ])
            ->values()
            ->all();
    }

    private function canSendBackToLevel1(Qualification $qualification, ?\App\Models\User $user): bool
    {
        if (! $user || ! $user->can('verification.level2.review')) {
            return false;
        }

        $vs = $qualification->verification_state;
        $allowed = [VerificationState::UnderLevel2Review, VerificationState::AutoVerifiedPendingLevel2];
        if (! $vs instanceof VerificationState || ! in_array($vs, $allowed, true)) {
            return false;
        }

        if (! $qualification->reviewed_at) {
            return false;
        }

        if ($qualification->returned_to_level1_at !== null) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildLevel1ReviewPayload(Qualification $qualification): ?array
    {
        if (! $qualification->reviewed_at) {
            return null;
        }

        $documents = $qualification->relationLoaded('documents')
            ? $qualification->documents
            : $qualification->documents()->with('uploadedBy')->get();

        $mapDocument = function ($document): array {
            return [
                'id' => $document->id,
                'document_type' => $document->document_type?->value ?? (string) $document->document_type,
                'original_name' => $document->original_name,
                'uploaded_by' => $document->uploadedBy?->name,
                'created_at' => optional($document->created_at)?->toIso8601String(),
                'preview_url' => route('admin.verification.documents.preview', ['document' => $document->id]),
                'download_url' => route('admin.verification.documents.download', ['document' => $document->id]),
            ];
        };

        $supportingAttachment = $documents
            ->where('document_type', \App\Enums\DocumentType::Level1ReviewAttachment)
            ->where('is_current_version', true)
            ->sortByDesc('id')
            ->first();

        $evaluationReport = $documents
            ->where('document_type', \App\Enums\DocumentType::Level1EvaluationReport)
            ->where('is_current_version', true)
            ->sortByDesc('id')
            ->first();

        $recommended = $qualification->level1_recommended_for_award;

        $typeCorrection = AuditLog::query()
            ->where('entity_type', Qualification::class)
            ->where('entity_id', $qualification->id)
            ->where('event_type', 'verification.level1_qualification_type_corrected')
            ->orderByDesc('created_at')
            ->first();

        $typeCorrectionPayload = null;
        if ($typeCorrection) {
            $metadata = (array) ($typeCorrection->metadata ?? []);
            $fromLabel = (string) ($metadata['old_qualification_type_label'] ?? '');
            $toLabel = (string) ($metadata['new_qualification_type_label'] ?? '');
            if ($fromLabel !== '' && $toLabel !== '') {
                $typeCorrectionPayload = [
                    'from_label' => $fromLabel,
                    'to_label' => $toLabel,
                    'message' => "Qualification type changed from {$fromLabel} to {$toLabel}",
                ];
            }
        }

        return [
            'recommended_for_award' => $recommended,
            'recommendation_label' => $recommended === null
                ? null
                : ($recommended ? 'Recommend recognition' : 'Recommend Rejection'),
            'findings' => $qualification->reviewer_notes,
            'accreditation_statement' => $qualification->level1_accreditation_statement,
            'level2_correction' => $this->buildLevel2Level1CorrectionMeta($qualification),
            'qualification_type_correction' => $typeCorrectionPayload,
            'submitted_by_name' => $qualification->level1ReviewCompletedBy?->name
                ?? $qualification->assignedVerifier?->name,
            'submitted_at' => optional($qualification->reviewed_at)?->toIso8601String(),
            'supporting_attachment' => $supportingAttachment ? $mapDocument($supportingAttachment) : null,
            'evaluation_report' => $evaluationReport ? $mapDocument($evaluationReport) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildLevel2Level1CorrectionMeta(Qualification $qualification): ?array
    {
        $log = AuditLog::query()
            ->where('entity_type', Qualification::class)
            ->where('entity_id', $qualification->id)
            ->where('event_type', 'verification.level2_corrected_level1_submission')
            ->orderByDesc('id')
            ->first();

        if (! $log) {
            return null;
        }

        $metadata = (array) ($log->metadata ?? []);

        return [
            'corrected_at' => optional($log->created_at)?->toIso8601String(),
            'corrected_by_name' => $log->actor_name_snapshot,
            'changed_fields' => $metadata['changed_fields'] ?? [],
        ];
    }

    /**
     * Officer send-back comments persist after the applicant resubmits (qualification row fields are cleared).
     * Amendment submissions are recorded as lifecycle events.
     *
     * @return list<array{kind: string, at: string|null, author_name: string|null, body: string|null, title: string|null, description: string|null}>
     */
    private function buildSendBackResubmissionTimeline(Qualification $qualification): array
    {
        $qid = $qualification->id;
        $appId = $qualification->application_id;
        if (! $appId) {
            return [];
        }

        $rows = collect();

        $comments = ApplicationComment::query()
            ->where('qualification_id', $qid)
            ->where('type', 'send_back')
            ->orderBy('created_at')
            ->with('author')
            ->get();

        foreach ($comments as $c) {
            $rows->push([
                'kind' => 'send_back',
                'at' => optional($c->created_at)?->toIso8601String(),
                'author_name' => $c->author?->name,
                'body' => $c->body,
                'title' => null,
                'description' => null,
            ]);
        }

        $amendEvents = ApplicationLifecycleEvent::query()
            ->where('application_id', $appId)
            ->where('event_code', 'like', 'submission.qualification_amended.q'.$qid.'.%')
            ->orderBy('occurred_at')
            ->get();

        foreach ($amendEvents as $e) {
            $rows->push([
                'kind' => 'resubmission',
                'at' => optional($e->occurred_at)?->toIso8601String(),
                'author_name' => $e->actor_name_snapshot,
                'body' => null,
                'title' => $e->title,
                'description' => $e->description,
            ]);
        }

        return $rows
            ->filter(fn (array $r) => is_string($r['at']) && $r['at'] !== '')
            ->sortBy('at')
            ->values()
            ->all();
    }

    private function recheckAutoVerificationDisabledReason(Qualification $qualification, bool $paymentSatisfied): ?string
    {
        $qualification->loadMissing('application');

        if (! $paymentSatisfied) {
            return 'Payment must be confirmed before auto-verification can be rechecked.';
        }

        if (! $qualification->application?->submitted_at) {
            return 'This application has not been submitted for verification.';
        }

        if ($qualification->verification_state === VerificationState::CertificateIssued) {
            return 'This qualification already has a certificate.';
        }

        if (in_array($qualification->verification_state, [VerificationState::Rejected, VerificationState::Closed, VerificationState::ReturnedToApplicant], true)) {
            return 'This qualification is not eligible for auto-verification recheck in its current state.';
        }

        if (! $this->qualificationHasAutoVerificationInput($qualification)) {
            return 'This qualification does not have enough matching data to recheck auto-verification.';
        }

        return null;
    }

    private function autoAssignLevel1DisabledReason(Qualification $qualification, bool $paymentSatisfied): ?string
    {
        $qualification->loadMissing(['application', 'assignedVerifier']);

        if (! $paymentSatisfied) {
            return 'Payment must be confirmed before Level 1 auto-assignment can run.';
        }

        if (! $qualification->application?->submitted_at) {
            return 'This application has not been submitted for verification.';
        }

        if (in_array($qualification->verification_state, [VerificationState::CertificateIssued, VerificationState::Rejected, VerificationState::Closed], true)) {
            return 'This qualification is not eligible for Level 1 auto-assignment in its current state.';
        }

        if ($qualification->verification_state === VerificationState::AutoVerifiedPendingLevel2) {
            return 'This qualification is awaiting a Level 2 decision.';
        }

        if ($qualification->assigned_verifier_id) {
            return 'This qualification is already assigned to '.($qualification->assignedVerifier?->name ?? 'a Level 1 officer').'. Use reassignment if you need to change the officer.';
        }

        if ($qualification->verification_state !== VerificationState::AwaitingAssignment && blank($qualification->assignment_failure_reason)) {
            return 'This qualification is not in the Level 1 assignment queue.';
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, QualificationCertificate>  $certificates
     * @return array{
     *     active: array<string, mixed>|null,
     *     revoked: array<int, array<string, mixed>>,
     *     superseded: array<int, array<string, mixed>>
     * }
     */
    private function buildCertificateHistoryPayload($certificates, bool $canRevoke): array
    {
        $active = null;
        $revoked = [];
        $superseded = [];

        foreach ($certificates as $cert) {
            $row = $this->formatCertificateHistoryRow($cert, $canRevoke);

            if ($cert->status === QualificationCertificate::STATUS_ISSUED) {
                $active = $row;
            } elseif ($cert->status === QualificationCertificate::STATUS_REVOKED) {
                $revoked[] = $row;
            } elseif ($cert->status === QualificationCertificate::STATUS_REISSUED) {
                $superseded[] = $row;
            }
        }

        return [
            'active' => $active,
            'revoked' => $revoked,
            'superseded' => $superseded,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCertificateHistoryRow(QualificationCertificate $cert, bool $canRevoke): array
    {
        $verificationUrl = rtrim((string) config('certificates.verify_url_base'), '/').'/'.$cert->verification_token;

        return [
            'id' => $cert->id,
            'certificate_number' => $cert->certificate_number,
            'certificate_type' => $cert->certificate_type ?: QualificationCertificate::TYPE_VERIFICATION,
            'certificate_type_label' => $cert->isRejectionCertificate() ? 'Rejection' : 'Verification',
            'status' => $cert->status,
            'issued_at' => optional($cert->issued_at)?->toIso8601String(),
            'revoked_at' => optional($cert->revoked_at)?->toIso8601String(),
            'revoked_by_name' => $cert->revokedBy?->name,
            'revocation_reason' => $cert->revocation_reason,
            'issued_by_name' => $cert->issuedBy?->name,
            'verification_url' => $verificationUrl,
            'admin_download_url' => route('admin.certificates.download', ['qualificationCertificate' => $cert]),
            'revoke_url' => $canRevoke && $cert->status === QualificationCertificate::STATUS_ISSUED
                ? route('admin.certificates.revoke', ['qualificationCertificate' => $cert])
                : null,
        ];
    }

    private function qualificationHasAutoVerificationInput(Qualification $qualification): bool
    {
        $hasStrongIdentifier = filled($qualification->student_number)
            || filled($qualification->certificate_number)
            || filled($qualification->nrc_passport_number);

        return $hasStrongIdentifier
            && filled($qualification->title_of_qualification)
            && filled($qualification->qualification_holder_name);
    }

    private function autoAssignFeedbackMessage(Qualification $qualification, User $actor, AutoAssignmentResult $result): string
    {
        if ($result->assigned) {
            if ($result->alreadyAssigned) {
                return 'Auto-assignment retried by '.$actor->name.'; qualification was already assigned to '.($qualification->assignedVerifier?->name ?? 'a Level 1 officer').'.';
            }

            return 'Auto-assignment retried by '.$actor->name.'; assigned to '.($qualification->assignedVerifier?->name ?? 'Level 1 officer').'.';
        }

        return 'Auto-assignment retried by '.$actor->name.'; failed: '.($result->failureReason ?? 'Unknown assignment error.');
    }

    /**
     * @return array<string, bool>
     */
    private function qualificationOfficerPermissions(?User $user): array
    {
        return [
            'level1_process' => (bool) $user?->can('verification.level1.process'),
            'level2_review' => (bool) $user?->can('verification.level2.review'),
            'approve' => (bool) $user?->can('verification.decide.approve'),
            'reject' => (bool) $user?->can('verification.decide.reject'),
            'issue_certificate' => (bool) $user?->can('verification.certificate.issue'),
            'is_super_admin' => (bool) $user?->hasRole('Super Admin'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qualificationEditDecisionContext(Qualification $qualification): array
    {
        $qualification->loadMissing(['application', 'level1ReviewCompletedBy', 'assignedVerifier', 'level2ReviewLockedBy']);
        $applicationModel = $qualification->application;
        $paymentSatisfaction = app(ApplicationPaymentSatisfaction::class);
        $paymentSatisfied = $applicationModel ? $paymentSatisfaction->isSatisfied($applicationModel) : false;

        $locks = app(QualificationLevel2ReviewLockService::class);
        $lockExpired = $locks->isExpired($qualification->level2_review_locked_at);
        $isLocked = (bool) $qualification->level2_review_locked_by && ! $lockExpired;
        $lockExpiresAt = $qualification->level2_review_locked_at
            ? $qualification->level2_review_locked_at->copy()->addMinutes($locks->ttlMinutes())
            : null;

        return [
            'verification_state' => $qualification->verification_state?->value
                ?? VerificationState::AwaitingAssignment->value,
            'reviewer_notes' => $qualification->reviewer_notes,
            'level1_review' => $this->buildLevel1ReviewPayload($qualification),
            'awarding_institution_accreditation_statement' => $this->awardingInstitutionAccreditationStatement($qualification),
            'awarding_institution_has_accreditation_statement' => $this->awardingInstitutionHasAccreditationStatement($qualification),
            'awarding_institution_id' => $qualification->awarding_institution_id,
            'level2_review_lock' => [
                'is_locked' => $isLocked,
                'locked_by_user_id' => $isLocked ? (int) $qualification->level2_review_locked_by : null,
                'locked_by_name' => $isLocked ? ($qualification->level2ReviewLockedBy?->name ?? null) : null,
                'locked_at' => $isLocked ? optional($qualification->level2_review_locked_at)?->toIso8601String() : null,
                'expires_at' => $isLocked ? optional($lockExpiresAt)?->toIso8601String() : null,
            ],
            'application' => [
                'payment_satisfied' => $paymentSatisfied,
            ],
        ];
    }

    private function awardingInstitutionAccreditationStatement(Qualification $qualification): ?string
    {
        $qualification->loadMissing('awardingInstitution');
        $statement = trim((string) ($qualification->awardingInstitution?->accreditation_statement ?? ''));

        return $statement !== '' ? $statement : null;
    }

    private function awardingInstitutionHasAccreditationStatement(Qualification $qualification): bool
    {
        return app(AwardingInstitutionAccreditationStatementService::class)->institutionHasStatement($qualification);
    }
}
