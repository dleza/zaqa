<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\DecisionService;
use App\Domain\Verification\SendBackService;
use App\Domain\Verification\VerificationReviewService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Verification\AssignApplicationRequest;
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
        $application->loadMissing([
            'applicant',
            'qualification.country',
            'qualification.awardingInstitution.country',
            'qualification.subjectResults',
            'documents.uploadedBy',
            'assignments.assignedBy',
            'assignments.assignedTo',
            'comments.author',
            'lifecycleEvents.actor',
            'statusHistories.changedBy',
            'invoice',
            'payments.proofDocument',
        ]);

        $paymentsSorted = $application->payments->sortByDesc('id');
        $displayPayment = $paymentsSorted->first(fn ($p) => $p->status === PaymentStatus::Confirmed)
            ?? $paymentsSorted->first();

        $level1Users = User::query()
            ->whereNull('applicant_type')
            ->whereHas('roles', fn ($q) => $q->where('name', 'Verification Officer Level 1'))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();

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
                    'email' => $application->applicant?->email,
                    'phone' => $application->applicant?->phone_primary,
                    'nrc_passport' => $application->qualification?->nrc_passport_number
                        ?: (function () use ($application) {
                            $subject = $application->metadata['verification_subject'] ?? null;
                            if (! is_array($subject)) {
                                return null;
                            }

                            return ($subject['nrc_number'] ?? null) ?: ($subject['passport_number'] ?? null);
                        })(),
                ],
                'qualification' => $application->qualification
                    ? [
                        'title' => $application->qualification->title_of_qualification,
                        'holder_name' => $application->qualification->qualification_holder_name,
                        'award_date' => $application->qualification->award_date,
                        'country' => $application->qualification->country?->name ?? $application->qualification->country_name_other,
                        'awarding_institution' => $application->qualification->awardingInstitution?->name ?? $application->qualification->awarding_institution_name_other,
                        'qualification_type' => $application->qualification->qualificationTypeMaster?->name,
                    ]
                    : null,
                'documents' => $application->documents
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
                'assignments' => $application->assignments
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
            'level1Users' => $level1Users,
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

    public function assign(AssignApplicationRequest $request, Application $application, AssignmentService $assignments): RedirectResponse
    {
        /** @var User $assignee */
        $assignee = User::query()->findOrFail((int) $request->validated('assigned_to_user_id'));

        $assignments->assign($application, $request->user(), $assignee, $request->validated('comment'));

        return back()->with('success', 'Assigned to Level 1.');
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
