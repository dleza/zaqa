<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Tracking\ApplicationLifecycleService;
use App\Domain\Verification\DecisionService;
use App\Domain\Verification\SendBackService;
use App\Domain\Verification\VerificationQualificationAccess;
use App\Domain\Verification\VerificationReviewService;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Verification\DecisionApproveRequest;
use App\Http\Requests\Admin\Verification\DecisionRejectRequest;
use App\Http\Requests\Admin\Verification\IssueCertificateRequest;
use App\Http\Requests\Admin\Verification\Level1CompleteRequest;
use App\Http\Requests\Admin\Verification\Level2ReturnToLevel1Request;
use App\Http\Requests\Admin\Verification\SendBackRequest;
use App\Http\Requests\Admin\Verification\StoreApplicationCommentRequest;
use App\Models\Application;
use App\Models\ApplicationComment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminVerificationApplicationController extends Controller
{
    public function show(Request $request, Application $application): Response
    {
        VerificationQualificationAccess::ensureApplicationHasAssignableQualification($request->user(), $application);

        $application->loadMissing([
            'applicant',
            'applicant.applicantProfile',
            'qualifications.country',
            'qualifications.awardingInstitution.country',
            'qualifications.qualificationTypeMaster',
            'qualifications.assignments.assignedBy',
            'qualifications.assignments.assignedTo',
            'qualification.country',
            'qualification.awardingInstitution.country',
            'qualification.subjectResults',
            'documents.uploadedBy',
            'comments.author',
            'lifecycleEvents.actor',
            'statusHistories.changedBy',
            'invoice',
            'payments.proofDocument',
        ]);

        $paymentsSorted = $application->payments->sortByDesc('id');
        $displayPayment = $paymentsSorted->first(fn ($p) => $p->status === PaymentStatus::Confirmed)
            ?? $paymentsSorted->first();

        $viewer = $request->user();
        $restricted = VerificationQualificationAccess::mustRestrictToAssignedQualifications($viewer);
        $visibleQualifications = ($restricted && $viewer)
            ? $application->qualifications->where('assigned_verifier_id', $viewer->id)->values()
            : $application->qualifications;

        $primaryQualification = $visibleQualifications->first() ?? $application->qualification;

        $qualCountTotal = $application->qualifications->count();
        $visibleQualificationIds = $visibleQualifications->pluck('id')->map(fn ($id) => (int) $id)->all();

        $documentsForPayload = $application->documents;
        if ($restricted && $viewer) {
            $documentsForPayload = $documentsForPayload->filter(function ($d) use ($visibleQualificationIds, $qualCountTotal, $visibleQualifications, $application) {
                if ($d->qualification_id !== null) {
                    return in_array((int) $d->qualification_id, $visibleQualificationIds, true);
                }
                if ($qualCountTotal > 1) {
                    return false;
                }
                if ($qualCountTotal === 1) {
                    $only = $application->qualifications->first();
                    if (! $only) {
                        return false;
                    }
                    if ($visibleQualifications->count() !== 1) {
                        return false;
                    }

                    return (int) $only->id === (int) $visibleQualifications->first()->id;
                }

                return false;
            });
        }

        /** Level 1 assignment history lives on each qualification (`qualification_assignments`), not on the application row. */
        $assignmentPayload = $visibleQualifications
            ->flatMap(function ($q) {
                return $q->assignments->map(fn ($a) => [
                    'id' => $a->id,
                    'qualification_id' => $q->id,
                    'qualification_title' => $q->title_of_qualification,
                    'assigned_by' => $a->assignedBy?->name,
                    'assigned_to' => $a->assignedTo?->name,
                    'comment' => $a->comment,
                    'assigned_at' => optional($a->assigned_at)?->toIso8601String(),
                    'unassigned_at' => optional($a->unassigned_at)?->toIso8601String(),
                ]);
            })
            ->sortByDesc(fn (array $row) => $row['assigned_at'] ?? '')
            ->values()
            ->all();

        return Inertia::render('Admin/Verification/Applications/Show', [
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'current_status' => $application->current_status?->value ?? (string) $application->current_status,
                'verification_state' => $application->verification_state?->value ?? null,
                'is_foreign' => (bool) $application->is_foreign,
                'service_deadline_at' => optional($application->service_deadline_at)?->toIso8601String(),
                'assigned_level1_user_id' => $application->assigned_level1_user_id,
                'assigned_by_level2_user_id' => $application->assigned_by_level2_user_id,
                'created_at' => optional($application->created_at)?->toIso8601String(),
                'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
                'completed_at' => optional($application->completed_at)?->toIso8601String(),
                'applicant' => [
                    'id' => $application->applicant?->id,
                    'name' => $application->metadata['verification_subject']['full_name'] ?? $application->applicant?->name,
                    'gender' => (function () use ($application) {
                        $subject = $application->metadata['verification_subject'] ?? null;
                        if (is_array($subject)) {
                            $g = trim((string) ($subject['gender'] ?? ''));
                            if ($g !== '') {
                                return $g;
                            }
                        }

                        return $application->applicant?->applicantProfile?->gender;
                    })(),
                    'email' => $application->applicant?->email,
                    'phone' => $application->applicant?->phone_primary,
                    'nrc_passport' => ($restricted ? $primaryQualification?->nrc_passport_number : $application->qualification?->nrc_passport_number)
                        ?: (function () use ($application) {
                            $subject = $application->metadata['verification_subject'] ?? null;
                            if (! is_array($subject)) {
                                return null;
                            }

                            return ($subject['nrc_number'] ?? null) ?: ($subject['passport_number'] ?? null);
                        })(),
                ],
                'qualification' => $primaryQualification
                    ? [
                        'title' => $primaryQualification->title_of_qualification,
                        'holder_name' => $primaryQualification->qualification_holder_name,
                        'award_date' => $primaryQualification->award_date,
                        'country' => $primaryQualification->country?->name ?? $primaryQualification->country_name_other,
                        'awarding_institution' => $primaryQualification->awardingInstitution?->name ?? $primaryQualification->awarding_institution_name_other,
                        'qualification_type' => $primaryQualification->qualificationTypeMaster?->name,
                    ]
                    : null,
                'qualifications' => $visibleQualifications
                    ->map(fn ($q) => [
                        'id' => $q->id,
                        'title' => $q->title_of_qualification,
                        'holder_name' => $q->qualification_holder_name,
                        'award_date' => $q->award_date,
                        'country' => $q->country?->name ?? $q->country_name_other,
                        'awarding_institution' => $q->awardingInstitution?->name ?? $q->awarding_institution_name_other,
                        'qualification_type' => $q->qualificationTypeMaster?->name,
                        'verification_state' => $q->verification_state?->value ?? (string) $q->verification_state,
                        'assigned_verifier_id' => $q->assigned_verifier_id,
                        'href' => route('admin.verification.qualifications.show', ['qualification' => $q->id]),
                    ])
                    ->values()
                    ->all(),
                'documents' => $documentsForPayload
                    ->sortByDesc('id')
                    ->values()
                    ->map(fn ($d) => [
                        'id' => $d->id,
                        'document_type' => $d->document_type?->value ?? (string) $d->document_type,
                        'original_name' => $d->original_name,
                        'mime_type' => $d->mime_type,
                        'size_bytes' => $d->size_bytes,
                        'version_number' => $d->version_number,
                        'is_current_version' => (bool) $d->is_current_version,
                        'uploaded_by' => $d->uploadedBy?->name,
                        'created_at' => optional($d->created_at)?->toIso8601String(),
                        'preview_url' => route('admin.verification.documents.preview', ['document' => $d->id]),
                        'download_url' => route('admin.verification.documents.download', ['document' => $d->id]),
                    ]),
                'invoice' => $application->invoice
                    ? [
                        'id' => $application->invoice->id,
                        'invoice_number' => $application->invoice->invoice_number,
                        'currency' => $application->invoice->currency,
                        'amount_cents' => $application->invoice->amount_cents,
                        'status' => $application->invoice->status?->value ?? (string) $application->invoice->status,
                        'issued_at' => optional($application->invoice->issued_at)?->toIso8601String(),
                        'paid_at' => optional($application->invoice->paid_at)?->toIso8601String(),
                    ]
                    : null,
                'latest_payment' => $displayPayment
                    ? (function ($p) {
                        return [
                            'id' => $p->id,
                            'method' => $p->method?->value ?? (string) $p->method,
                            'status' => $p->status?->value ?? (string) $p->status,
                            'currency' => $p->currency,
                            'amount_cents' => $p->amount_cents,
                            'provider' => $p->provider,
                            'provider_reference' => $p->provider_reference,
                            'created_at' => optional($p->created_at)?->toIso8601String(),
                            'confirmed_at' => optional($p->confirmed_at)?->toIso8601String(),
                            'proof_document' => $p->proofDocument
                                ? [
                                    'id' => $p->proofDocument->id,
                                    'original_name' => $p->proofDocument->original_name,
                                    'preview_url' => route('admin.verification.documents.preview', ['document' => $p->proofDocument->id]),
                                    'download_url' => route('admin.verification.documents.download', ['document' => $p->proofDocument->id]),
                                ]
                                : null,
                        ];
                    })($displayPayment)
                    : null,
                'assignments' => $assignmentPayload,
                'comments' => $application->comments
                    ->sortByDesc('id')
                    ->values()
                    ->map(fn ($c) => [
                        'id' => $c->id,
                        'type' => $c->type,
                        'visibility' => $c->visibility,
                        'body' => $c->body,
                        'author_name' => $c->author?->name,
                        'created_at' => optional($c->created_at)?->toIso8601String(),
                    ]),
                'lifecycle' => $application->lifecycleEvents
                    ->sortByDesc('occurred_at')
                    ->take(40)
                    ->values()
                    ->map(fn ($e) => [
                        'id' => $e->id,
                        'title' => $e->title,
                        'description' => $e->description,
                        'comment' => $e->comment,
                        'visibility' => $e->visibility?->value ?? (string) $e->visibility,
                        'occurred_at' => optional($e->occurred_at)?->toIso8601String(),
                        'actor_name' => $e->actor_name_snapshot,
                        'stage' => $e->stage?->value ?? (string) $e->stage,
                    ]),
            ],
            'viewerUserId' => $request->user()?->id,
            'can' => [
                'assign' => (bool) $request->user()?->can('verification.assign'),
                'send_back' => (bool) $request->user()?->can('verification.send_back'),
                'level1_process' => (bool) $request->user()?->can('verification.level1.process'),
                'level2_review' => (bool) $request->user()?->can('verification.level2.review'),
                'approve' => (bool) $request->user()?->can('verification.decide.approve'),
                'reject' => (bool) $request->user()?->can('verification.decide.reject'),
                'issue' => (bool) $request->user()?->can('verification.certificate.issue'),
                'finance_view' => (bool) $request->user()?->can('admin.finance.view'),
            ],
        ]);
    }

    public function sendBack(SendBackRequest $request, Application $application, SendBackService $sendBack): RedirectResponse
    {
        $sendBack->sendBackToApplicant($application, $request->user(), (string) $request->validated('comment'));

        return back()->with('success', 'Sent back to applicant.');
    }

    public function level1Complete(Level1CompleteRequest $request, Application $application, VerificationReviewService $reviews): RedirectResponse
    {
        $reviews->level1Complete($application, $request->user(), (string) $request->validated('findings'));

        return back()->with('success', 'Level 1 review completed.');
    }

    public function level2ReturnToLevel1(Level2ReturnToLevel1Request $request, Application $application, VerificationReviewService $reviews): RedirectResponse
    {
        $reviews->level2ReturnToLevel1($application, $request->user(), (string) $request->validated('comment'));

        return back()->with('success', 'Returned to Level 1.');
    }

    public function approve(DecisionApproveRequest $request, Application $application, DecisionService $decisions): RedirectResponse
    {
        $decisions->approve($application, $request->user(), $request->validated('comment'));

        return back()->with('success', 'Application approved.');
    }

    public function reject(DecisionRejectRequest $request, Application $application, DecisionService $decisions): RedirectResponse
    {
        $decisions->reject($application, $request->user(), (string) $request->validated('reason'));

        return back()->with('success', 'Application rejected.');
    }

    public function issueCertificate(IssueCertificateRequest $request, Application $application, DecisionService $decisions): RedirectResponse
    {
        $decisions->issueCertificate($application, $request->user(), $request->validated('comment'));

        return back()->with('success', 'Certificate issued (hook).');
    }

    public function storeComment(
        StoreApplicationCommentRequest $request,
        Application $application,
        ApplicationLifecycleService $lifecycle,
    ): RedirectResponse {
        $body = trim((string) $request->validated('body'));
        $visibility = (string) $request->validated('visibility');
        $type = trim((string) ($request->validated('type') ?? 'general'));
        if ($type === '') {
            $type = 'general';
        }

        DB::transaction(function () use ($application, $request, $body, $visibility, $type, $lifecycle) {
            $application->refresh();

            $comment = ApplicationComment::create([
                'application_id' => $application->id,
                'author_user_id' => $request->user()?->id,
                'type' => $type,
                'visibility' => $visibility,
                'body' => $body,
            ]);

            if ($visibility === 'applicant_visible') {
                $lifecycle->event(
                    application: $application,
                    eventType: 'comment',
                    eventCodeBase: 'comment.added',
                    stage: LifecycleStage::Review,
                    title: 'Comment from ZAQA',
                    description: 'ZAQA added a comment on your application.',
                    visibility: LifecycleVisibility::Both,
                    actor: $request->user(),
                    comment: $body,
                    metadata: [
                        'comment_id' => $comment->id,
                        'type' => $type,
                    ],
                    occurredAt: now(),
                );
            }
        });

        return back()->with('success', $visibility === 'applicant_visible' ? 'Comment sent to applicant.' : 'Internal comment saved.');
    }
}
