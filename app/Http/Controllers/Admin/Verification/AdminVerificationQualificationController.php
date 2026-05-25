<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Applications\QualificationCaptureService;
use App\Domain\Certificates\QualificationCertificateService;
use App\Domain\Payments\ApplicationPaymentSatisfaction;
use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\AutoVerifiedQualificationReviewService;
use App\Domain\Verification\QualificationDecisionService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Domain\Verification\QualificationLevel2ReviewLockService;
use App\Domain\Verification\QualificationSendBackService;
use App\Domain\Verification\VerificationQualificationAccess;
use App\Enums\VerificationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Verification\AdminUpdateVerificationQualificationRequest;
use App\Http\Requests\Admin\Verification\AssignApplicationRequest;
use App\Http\Requests\Admin\Verification\IssueQualificationCertificateRequest;
use App\Http\Requests\Admin\Verification\QualificationDecisionApproveRequest;
use App\Http\Requests\Admin\Verification\QualificationDecisionRejectRequest;
use App\Http\Requests\Admin\Verification\QualificationLevel1CompleteRequest;
use App\Http\Requests\Admin\Verification\RevokeQualificationAssignmentRequest;
use App\Http\Requests\Admin\Verification\SendBackRequest;
use App\Models\ApplicationComment;
use App\Models\ApplicationLifecycleEvent;
use App\Models\CertificateSubject;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\QualificationType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AdminVerificationQualificationController extends Controller
{
    public function show(Request $request, Qualification $qualification): Response
    {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

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
            'learnerRecord.awardingInstitution',
            'learnerRecordMatchAttempts.learnerRecord',
            'level2ReviewLockedBy',
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

        $canIssueCveq = (bool) $request->user()?->can('verification.certificate.issue')
            && $paymentSatisfied
            && $qualification->verification_state === VerificationState::ApprovedForCertificate
            && ! $applicationBlockedForCertificateIssue
            && ! $activeCveq;

        $canReissueCveq = (bool) $request->user()?->hasRole('Super Admin')
            && (bool) $request->user()?->can('verification.certificate.issue')
            && $paymentSatisfied
            && $qualification->verification_state === VerificationState::CertificateIssued
            && ! $applicationBlockedForCertificateIssue
            && $activeCveq instanceof QualificationCertificate;

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
                'reviewer_notes' => $qualification->reviewer_notes,
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
                ],
                'cveq_certificate' => $activeCveq
                    ? [
                        'certificate_number' => $activeCveq->certificate_number,
                        'issued_at' => optional($activeCveq->issued_at)?->toIso8601String(),
                        'admin_download_url' => route('admin.verification.qualifications.certificate.download', ['qualification' => $qualification]),
                    ]
                    : null,
                'issue_certificate_url' => route('admin.verification.qualifications.issue_certificate', ['qualification' => $qualification]),
                'can_issue_cveq_certificate' => $canIssueCveq,
                'can_reissue_cveq_certificate' => $canReissueCveq,
                'qualification_type' => $qualification->qualificationTypeMaster?->name,
                'title' => $qualification->title_of_qualification,
                'applicant_entered_qualification_title' => $qualification->applicant_entered_qualification_title,
                'verified_qualification_title' => $qualification->verified_qualification_title,
                'qualification_title_source' => $qualification->qualification_title_source?->value ?? (string) ($qualification->qualification_title_source ?? ''),
                'awarding_institution' => $qualification->awardingInstitution?->name ?? $qualification->awarding_institution_name_other ?? $qualification->awarding_institution_name,
                'country' => $qualification->country?->name ?? $qualification->country_name_other,
                'holder_name' => $qualification->qualification_holder_name,
                'holder_nrc_passport' => $qualification->nrc_passport_number,
                'student_number' => $qualification->student_number,
                'certificate_number' => $qualification->certificate_number,
                'award_date' => optional($qualification->award_date)?->format('Y-m-d'),
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
                'documents' => $qualification->documents
                    ->sortByDesc('id')
                    ->values()
                    ->map(fn ($d) => [
                        'id' => $d->id,
                        'document_type' => $d->document_type?->value ?? (string) $d->document_type,
                        'original_name' => $d->original_name,
                        'version_number' => $d->version_number,
                        'is_current_version' => (bool) $d->is_current_version,
                        'uploaded_by' => $d->uploadedBy?->name,
                        'created_at' => optional($d->created_at)?->toIso8601String(),
                        'preview_url' => route('admin.verification.documents.preview', ['document' => $d->id]),
                        'download_url' => route('admin.verification.documents.download', ['document' => $d->id]),
                    ]),
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
            'can' => [
                'assign' => (bool) $request->user()?->can('verification.assign'),
                'send_back' => (bool) $request->user()?->can('verification.send_back'),
                'level1_process' => (bool) $request->user()?->can('verification.level1.process'),
                'level2_review' => (bool) $request->user()?->can('verification.level2.review'),
                'approve' => (bool) $request->user()?->can('verification.decide.approve'),
                'reject' => (bool) $request->user()?->can('verification.decide.reject'),
                'edit_qualification' => (bool) ($request->user()?->can('verification.level1.process') || $request->user()?->can('verification.level2.review')),
                'issue_certificate' => (bool) $request->user()?->can('verification.certificate.issue'),
                'is_super_admin' => (bool) $request->user()?->hasRole('Super Admin'),
            ],
        ]);
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

    public function edit(Request $request, Qualification $qualification): Response
    {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);
        abort_unless(
            $request->user()->can('verification.level1.process') || $request->user()->can('verification.level2.review'),
            403
        );

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
            'application',
        ]);

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

        $awardingInstitutionId = $qualification->awarding_institution_id;
        $institutionField = $awardingInstitutionId
            ? $awardingInstitutionId
            : (trim((string) ($qualification->awarding_institution_name_other ?? '')) !== '' ? 'other' : '');

        return Inertia::render('Admin/Verification/Qualifications/Edit', [
            'qualification' => [
                'id' => $qualification->id,
                'qualification_holder_name' => $qualification->qualification_holder_name,
                'nrc_passport_number' => $qualification->nrc_passport_number,
                'country_id' => $qualification->country_id,
                'country_name_other' => $qualification->country_name_other,
                'awarding_institution_id' => $institutionField,
                'awarding_institution_name_other' => $qualification->awarding_institution_name_other,
                'awarding_institution_name' => $qualification->awarding_institution_name,
                'certificate_number' => $qualification->certificate_number,
                'student_number' => $qualification->student_number,
                'examination_number' => $qualification->examination_number,
                'title_of_qualification' => $qualification->title_of_qualification,
                'award_date' => $qualification->award_date?->format('Y-m-d'),
                'qualification_type_id' => $qualification->qualification_type_id,
                'transcript_reason' => $qualification->transcript_reason,
                'notes' => $qualification->notes,
                'subject_results' => $qualification->subjectResults->map(fn ($r) => [
                    'certificate_subject_id' => $r->certificate_subject_id,
                    'grade' => $r->grade,
                ])->values()->all(),
            ],
            'application' => [
                'id' => $qualification->application_id,
                'application_number' => $qualification->application?->application_number,
            ],
            'countries' => $countries,
            'qualificationTypes' => $qualificationTypes->map(fn (QualificationType $t) => [
                'id' => $t->id,
                'zqf_level_code' => $t->zqf_level_code,
                'level_label' => $t->level_label,
                'name' => $t->name,
                'requires_subject_results' => (bool) $t->requires_subject_results,
            ])->values()->all(),
            'certificateSubjects' => $certificateSubjects,
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

        $capture->adminVerificationCorrection($qualification, $request->validated(), $request->user());

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

    public function level1Complete(QualificationLevel1CompleteRequest $request, Qualification $qualification, QualificationLevel1ReviewService $reviews): RedirectResponse
    {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        $reviews->completeLevel1(
            $qualification,
            $request->user(),
            (string) $request->validated('findings'),
            $request->file('attachment'),
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
    ): RedirectResponse {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        $decisions->reject(
            $qualification,
            $request->user(),
            (string) $request->validated('reason'),
        );

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
}
