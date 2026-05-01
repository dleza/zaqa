<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Verification\AssignmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Verification\AssignApplicationRequest;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminVerificationQualificationController extends Controller
{
    public function show(Request $request, Qualification $qualification): Response
    {
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
                'verification_state' => $qualification->verification_state,
                'is_foreign' => (bool) $qualification->is_foreign_qualification,
                'assigned_verifier_id' => $qualification->assigned_verifier_id,
                'assigned_at' => optional($qualification->assigned_at)?->toIso8601String(),
                'reviewed_at' => optional($qualification->reviewed_at)?->toIso8601String(),
                'reviewer_notes' => $qualification->reviewer_notes,
                'fee_currency' => $qualification->fee_currency,
                'fee_amount_cents' => $qualification->fee_amount_cents,
                'application' => [
                    'id' => $qualification->application?->id,
                    'application_number' => $qualification->application?->application_number,
                    'current_status' => $qualification->application?->current_status?->value ?? (string) $qualification->application?->current_status,
                    'payment_status' => $qualification->application?->paid_at ? 'paid' : 'unpaid',
                    'submitted_at' => optional($qualification->application?->submitted_at)?->toIso8601String(),
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
            ],
        ]);
    }

    public function assign(AssignApplicationRequest $request, Qualification $qualification, AssignmentService $assignments): RedirectResponse
    {
        /** @var User $assignee */
        $assignee = User::query()->findOrFail((int) $request->validated('assigned_to_user_id'));

        $assignments->assign($qualification, $request->user(), $assignee, $request->validated('comment'));

        return back()->with('success', 'Assigned to verifier.');
    }
}

