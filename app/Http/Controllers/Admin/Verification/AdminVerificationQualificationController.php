<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Applications\QualificationCaptureService;
use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Domain\Verification\QualificationSendBackService;
use App\Domain\Verification\VerificationQualificationAccess;
use App\Enums\VerificationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Verification\AdminUpdateVerificationQualificationRequest;
use App\Http\Requests\Admin\Verification\AssignApplicationRequest;
use App\Http\Requests\Admin\Verification\QualificationLevel1CompleteRequest;
use App\Http\Requests\Admin\Verification\RevokeQualificationAssignmentRequest;
use App\Http\Requests\Admin\Verification\SendBackRequest;
use App\Models\CertificateSubject;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
        ]);

        $level1Users = User::query()
            ->whereNull('applicant_type', 'and', false)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Verification Officer Level 1'))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();

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
                    'submitted_at' => optional($qualification->application?->submitted_at)?->toIso8601String(),
                    'created_at' => optional($qualification->application?->created_at)?->toIso8601String(),
                    'service_deadline_at' => optional($qualification->application?->service_deadline_at)?->toIso8601String(),
                    'completed_at' => optional($qualification->application?->completed_at)?->toIso8601String(),
                    'applicant_name' => $qualification->application?->metadata['verification_subject']['full_name'] ?? $qualification->application?->applicant?->name,
                ],
                'qualification_type' => $qualification->qualificationTypeMaster?->name,
                'title' => $qualification->title_of_qualification,
                'awarding_institution' => $qualification->awardingInstitution?->name ?? $qualification->awarding_institution_name_other ?? $qualification->awarding_institution_name,
                'country' => $qualification->country?->name ?? $qualification->country_name_other,
                'holder_name' => $qualification->qualification_holder_name,
                'holder_nrc_passport' => $qualification->nrc_passport_number,
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
            'viewerUserId' => $request->user()?->id,
            'level1Users' => $level1Users,
            'can' => [
                'assign' => (bool) $request->user()?->can('verification.assign'),
                'send_back' => (bool) $request->user()?->can('verification.send_back'),
                'level1_process' => (bool) $request->user()?->can('verification.level1.process'),
                'edit_qualification' => (bool) ($request->user()?->can('verification.level1.process') || $request->user()?->can('verification.level2.review')),
            ],
        ]);
    }

    public function edit(Request $request, Qualification $qualification): Response
    {
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);
        abort_unless(
            $request->user()->can('verification.level1.process') || $request->user()->can('verification.level2.review'),
            403
        );

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

        $sendBack->sendBackToApplicant($qualification, $request->user(), (string) $request->validated('comment'));

        return back()->with('success', 'Qualification sent back to applicant.');
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
}
