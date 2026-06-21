<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Applications\ApplicantQualificationAmendmentComments;
use App\Domain\Applications\ApplicantQualificationAmendmentGuard;
use App\Domain\Applications\ApplicationDraftService;
use App\Domain\Applications\ApplicationQualificationOutcomeSyncService;
use App\Domain\Documents\QualificationDocumentEvidence;
use App\Domain\Applicant\ApplicantQualificationsService;
use App\Domain\Documents\ApplicantDocumentService;
use App\Domain\Finance\InvoiceDocumentPresenter;
use App\Domain\Finance\InvoicePdfService;
use App\Domain\Finance\PaymentReceiptPdfService;
use App\Domain\Payments\ApplicationPaymentSatisfaction;
use App\Domain\Applications\ApplicationSubmissionReadinessService;
use App\Domain\Payments\InvoiceService;
use App\Domain\Payments\Presenters\ApplicantPaymentAttemptStatusPresenter;
use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceType;
use App\Enums\VerificationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\CreateApplicationDraftRequest;
use App\Http\Requests\Applicant\SaveWizardDeclarationsRequest;
use App\Http\Requests\Applicant\UpdateApplicationDraftRequest;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\BillingCategory;
use App\Models\CertificateSubject;
use App\Models\Country;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\QualificationType;
use App\Support\Qualifications\CertificateSubjectGrade;
use App\Support\Qualifications\QualificationAwardingInstitutionFormState;
use App\Support\Applications\ApplicationSubmissionMode;
use App\Domain\Applications\InstitutionalMultipleOverviewService;
use App\Domain\Applications\InstitutionalMultipleWizardService;
use App\Models\User;
use App\Support\CountryIso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantApplicationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $user?->loadMissing(['applicantProfile', 'institutionProfile']);

        $applications = Application::query()
            ->where('applicant_user_id', $user->id)
            ->with([
                'qualifications.qualificationTypeMaster',
                'qualifications.subjectResults',
                'qualifications.consentForm',
                'qualifications.awardingInstitution.country',
                'qualifications.country',
                'qualification.qualificationTypeMaster',
                'qualification.subjectResults',
                'qualification.consentForm',
                'qualification.awardingInstitution.country',
                'qualification.country',
                'documents',
                'invoice',
                'payments',
                'consentForm',
            ])
            ->withCount([
                'qualifications as returned_qualifications_count' => fn ($q) => $q->where('verification_state', VerificationState::ReturnedToApplicant->value),
            ])
            ->latest('id')
            ->get()
            ->map(function (Application $application) use ($request, $user) {
                $application = app(ApplicationQualificationOutcomeSyncService::class)->syncIfNeeded($application);

                return [
                    'id' => $application->id,
                    'uuid' => $application->uuid,
                    'application_number' => $application->application_number,
                    'current_status' => $application->current_status?->value ?? (string) $application->current_status,
                    'status_label' => $application->applicantStatusLabel(),
                    'display_status_label' => ((int) ($application->returned_qualifications_count ?? 0)) > 0
                        ? 'Correction required'
                        : $application->applicantDisplayStatusLabel(),
                    'list_status_label' => $this->applicantApplicationsListStatusLabel($application),
                    'correction_required' => ((int) ($application->returned_qualifications_count ?? 0)) > 0,
                    'service_type' => $application->service_type?->value ?? (string) $application->service_type,
                    'qualification_category' => $application->qualification_category,
                    'is_foreign' => (bool) $application->is_foreign,
                    'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
                    'created_at' => optional($application->created_at)?->toIso8601String(),
                    'can_edit' => $request->user()->can('update', $application),
                    'can_delete' => $request->user()->can('delete', $application),
                    'wizard' => $this->wizardSummary($application, $user),
                ];
            });

        return Inertia::render('Applicant/Applications/Index', [
            'applications' => $applications,
        ]);
    }

    private function applicantApplicationsListStatusLabel(Application $application): string
    {
        if ($application->hasQualificationsAwaitingCorrection()) {
            return 'Correction required';
        }

        $status = $application->current_status?->value ?? (string) $application->current_status;

        if (in_array($status, [
            ApplicationStatus::Submitted->value,
            ApplicationStatus::Resubmitted->value,
            ApplicationStatus::InProgress->value,
        ], true)) {
            return 'Processing';
        }

        return $application->applicantDisplayStatusLabel();
    }

    /**
     * Mirrors the applicant edit wizard: Applicant → Qualification → Declarations → Payment.
     * Subjects and per-qualification documents roll into the Qualification step (same as Edit.vue).
     */
    private function wizardSummary(Application $application, User $user): array
    {
        if (ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
            return app(InstitutionalMultipleWizardService::class)->wizardSummary($application, $user);
        }

        if (! $user->can('update', $application)) {
            return [
                'current_step' => null,
                'edit_href' => null,
            ];
        }

        $applicantDone = $this->wizardApplicantStepComplete($application, $user);
        $qualificationDone = $this->wizardQualificationStepComplete($application);
        $wd = (array) (($application->metadata ?? [])['wizard_declarations'] ?? []);
        $termsAt = $wd['terms_accepted_at'] ?? null;
        $confirmedAt = $wd['information_confirmed_at'] ?? null;
        $declarationsDone = is_string($termsAt) && trim($termsAt) !== ''
            && is_string($confirmedAt) && trim($confirmedAt) !== '';
        $paymentDone = $this->wizardPaymentStepComplete($application);

        $steps = [
            ['key' => 'applicant', 'label' => 'Applicant', 'done' => $applicantDone],
            ['key' => 'qualification', 'label' => 'Qualification', 'done' => $qualificationDone],
            ['key' => 'consent', 'label' => 'Declarations', 'done' => $declarationsDone],
            ['key' => 'payment', 'label' => 'Payment', 'done' => $paymentDone],
        ];

        $currentIndex = 0;
        foreach ($steps as $idx => $s) {
            if (! (bool) $s['done']) {
                $currentIndex = $idx;
                break;
            }
            $currentIndex = $idx;
        }

        $current = $steps[$currentIndex] ?? $steps[0];

        return [
            'current_step' => [
                'index' => $currentIndex + 1,
                'total' => count($steps),
                'key' => $current['key'],
                'label' => $current['label'],
                'done' => (bool) $current['done'],
            ],
            'edit_href' => route('applicant.applications.edit', [
                'application' => $application->id,
                'step' => $current['key'],
            ]),
        ];
    }

    private function wizardApplicantStepComplete(Application $application, User $user): bool
    {
        $email = trim((string) ($user->email ?? ''));
        $phone = trim((string) ($user->phone_primary ?? ''));

        // Applicants may register with a single primary contact method (email OR phone).
        if ($email === '' && $phone === '') {
            return false;
        }

        $meta = (array) ($application->metadata ?? []);
        $submittingFor = trim((string) ($meta['submitting_for'] ?? 'self'));
        $applicantType = $user->applicant_type?->value ?? '';

        if ($submittingFor === 'other') {
            $vs = (array) ($meta['verification_subject'] ?? []);
            $fullName = trim((string) ($vs['full_name'] ?? ''));
            $nrc = trim((string) ($vs['nrc_number'] ?? ''));
            $passport = trim((string) ($vs['passport_number'] ?? ''));
            if ($fullName === '' || ($nrc === '' && $passport === '')) {
                return false;
            }
        } elseif ($applicantType === ApplicantType::Individual->value) {
            $profile = $user->applicantProfile;
            $nrc = trim((string) ($profile?->nrc_number ?? ''));
            $passport = trim((string) ($profile?->passport_number ?? ''));
            if ($nrc === '' && $passport === '') {
                return false;
            }
        }

        $hasApplicationIdentity = $application->documents->contains(function ($d) {
            $v = $d->document_type?->value ?? (string) $d->document_type;

            return (bool) $d->is_current_version
                && $d->deleted_at === null
                && in_array($v, [DocumentType::NrcCopy->value, DocumentType::PassportCopy->value], true);
        });

        if ($hasApplicationIdentity) {
            return true;
        }

        if ($submittingFor !== 'other') {
            $uploadedAt = $user->applicantProfile?->identity_document_uploaded_at ?? null;
            if ($uploadedAt !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, Qualification>
     */
    private function wizardQualifications(Application $application): Collection
    {
        $application->loadMissing([
            'qualifications.qualificationTypeMaster',
            'qualifications.subjectResults',
            'qualifications.consentForm',
            'qualifications.awardingInstitution.country',
            'qualifications.country',
            'qualification.qualificationTypeMaster',
            'qualification.subjectResults',
            'qualification.consentForm',
            'qualification.awardingInstitution.country',
            'qualification.country',
        ]);

        $list = $application->qualifications;
        if ($list->isEmpty() && $application->qualification) {
            return collect([$application->qualification]);
        }

        return $list;
    }

    private function wizardQualificationStepComplete(Application $application): bool
    {
        $quals = $this->wizardQualifications($application);
        if ($quals->isEmpty()) {
            return false;
        }

        foreach ($quals as $q) {
            if (! $this->wizardSingleQualificationComplete($application, $q)) {
                return false;
            }
        }

        return true;
    }

    private function wizardSingleQualificationComplete(Application $application, Qualification $q): bool
    {
        if (trim((string) ($q->qualification_holder_name ?? '')) === ''
            || trim((string) ($q->nrc_passport_number ?? '')) === ''
            || ! (bool) $q->country_id
            || (! (bool) $q->awarding_institution_id && trim((string) ($q->awarding_institution_name_other ?? '')) === '')
            || trim((string) ($q->title_of_qualification ?? '')) === ''
            || trim((string) ($q->names_as_on_qualification_document ?? '')) === ''
            || empty($q->award_date)
            || (int) ($q->qualification_type_id ?? 0) < 1) {
            return false;
        }

        $idNum = trim((string) ($q->certificate_number ?? ''))
            .trim((string) ($q->student_number ?? ''))
            .trim((string) ($q->examination_number ?? ''));
        if ($idNum === '') {
            return false;
        }

        if (! $this->wizardQualificationSubjectsSatisfied($q)) {
            return false;
        }

        $qid = (int) $q->id;

        if (! $this->wizardHasQualificationDocument($application, $qid, DocumentType::CertificateCopy)) {
            return false;
        }

        $foreign = $this->wizardQualificationInstitutionIsForeign($q);
        if ($foreign) {
            $consentOk = (bool) ($q->consentForm?->uploaded_document_id ?? false);
            if (! $consentOk) {
                return false;
            }
        }

        return true;
    }

    private function wizardQualificationSubjectsSatisfied(Qualification $q): bool
    {
        $type = $q->qualificationTypeMaster;
        if (! $type || ! $type->requires_subject_results) {
            return true;
        }

        $rows = $q->subjectResults ?? collect();
        if ($rows->count() === 0) {
            return false;
        }

        foreach ($rows as $r) {
            $gradeOk = trim((string) ($r->grade ?? '')) !== '';
            $catalogId = (int) ($r->certificate_subject_id ?? 0);
            $subjectOk = $catalogId > 0 || trim((string) ($r->subject_name ?? '')) !== '';
            if (! $gradeOk || ! $subjectOk) {
                return false;
            }
        }

        return true;
    }

    private function wizardQualificationInstitutionIsForeign(Qualification $q): bool
    {
        $instIso = strtoupper((string) (($q->awardingInstitution?->country?->iso_code) ?: ($q->country?->iso_code) ?: ''));

        return $instIso !== '' && ! CountryIso::isZambia($instIso);
    }

    private function wizardHasQualificationDocument(Application $application, int $qualificationId, DocumentType $type): bool
    {
        $want = $type->value;

        return $application->documents->contains(function ($d) use ($qualificationId, $want) {
            $v = $d->document_type?->value ?? (string) $d->document_type;

            return (bool) $d->is_current_version
                && $d->deleted_at === null
                && $v === $want
                && (int) ($d->qualification_id ?? 0) === $qualificationId;
        });
    }

    private function wizardPaymentStepComplete(Application $application): bool
    {
        $application->loadMissing('qualifications.certificates', 'payments', 'invoice');

        return app(ApplicationPaymentSatisfaction::class)->isSatisfied($application);
    }

    public function create(Request $request): Response
    {
        $request->user()?->loadMissing(['applicantProfile', 'institutionProfile']);

        return Inertia::render('Applicant/Applications/New', [
            'qualificationTypes' => array_map(
                fn (QualificationType $t) => [
                    'id' => $t->id,
                    'zqf_level_code' => $t->zqf_level_code,
                    'level_label' => $t->level_label,
                    'name' => $t->name,
                ],
                QualificationType::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get()
                    ->all(),
            ),
            'applicant' => $this->applicantPayload($request),
        ]);
    }

    public function store(
        CreateApplicationDraftRequest $request,
        ApplicationDraftService $drafts,
        ApplicantDocumentService $documents,
        \App\Domain\Applicants\ApplicantIdentityDocumentService $identityDocuments,
    ): RedirectResponse
    {
        $validated = $request->validated();
        $validated['service_type'] = ServiceType::Verification->value;

        $application = $drafts->createDraft($request->user(), $validated);

        if ($request->hasFile('identity_file')) {
            $submittingFor = (string) ($validated['submitting_for'] ?? 'self');
            $rawIdentityType = strtolower(trim((string) ($validated['identity_type'] ?? 'nrc')));
            $type = $rawIdentityType === 'passport' ? DocumentType::PassportCopy : DocumentType::NrcCopy;

            if ($submittingFor === 'other') {
                $documents->upload($application, $type, $request->file('identity_file'), $request->user(), null);
            } else {
                $identityDocuments->saveProfileIdentityDocument($request->user(), $request->file('identity_file'), $request->user());
            }
        }

        return redirect()->route('applicant.applications.edit', ['application' => $application, 'step' => 'qualification'])
            ->with('success', 'Your application is ready. Add qualifications below—you can return to the Applicant step anytime to update holder documents.');
    }

    public function show(Request $request, Application $application): Response
    {
        $this->authorize('view', $application);

        $application->load([
            'qualifications.subjectResults',
            'qualifications.certificates',
            'qualifications.country',
            'qualifications.awardingInstitution.country',
            'qualifications.qualificationTypeMaster.billingCategory',
            'qualifications.consentForm',
            'qualification.subjectResults',
            'qualification.country',
            'qualification.awardingInstitution',
            'qualification.qualificationTypeMaster.billingCategory',
            'documents',
            'consentForm',
            'statusHistories',
            'invoice',
            'payments.proofDocument',
        ]);
        $request->user()?->loadMissing(['applicantProfile', 'institutionProfile']);

        $institutionalOverview = null;
        if (ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
            $institutionalOverview = app(InstitutionalMultipleOverviewService::class)->build($application);
        }

        return Inertia::render('Applicant/Applications/Show', [
            'application' => $this->applicationPayload($request, $application),
            'countries' => $this->countryOptions(),
            'awardingInstitutions' => $this->awardingInstitutionOptions(),
            'localConsent' => (array) config('consent.local'),
            'applicant' => $this->applicantPayload($request),
            'institutional_overview' => $institutionalOverview,
        ]);
    }

    public function edit(Request $request, Application $application): Response|RedirectResponse
    {
        $this->authorize('view', $application);

        if (ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
            return redirect()->route('applicant.applications.multiple.edit', [
                'application' => $application->id,
                'step' => $request->query('step'),
            ]);
        }

        if (! $request->user()->can('update', $application)) {
            return redirect()->route('applicant.applications.show', $application);
        }

        return $this->buildEditInertiaResponse($request, $application, null);
    }

    public function amendQualification(Request $request, Application $application, Qualification $qualification): Response|RedirectResponse
    {
        $this->authorize('view', $application);

        if ((int) $qualification->application_id !== (int) $application->id) {
            abort(404);
        }

        if ($qualification->verification_state !== VerificationState::ReturnedToApplicant) {
            return redirect()->route('applicant.applications.show', $application);
        }

        if (! $request->user()->can('update', $application)) {
            return redirect()->route('applicant.applications.show', $application);
        }

        if (ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
            return redirect()->route('applicant.applications.multiple.qualifications.edit', [
                'application' => $application->id,
                'qualification' => $qualification->id,
            ]);
        }

        return $this->buildEditInertiaResponse($request, $application, $qualification->id);
    }

    public function createQualificationWorkspace(Request $request, Application $application): Response|RedirectResponse
    {
        $this->authorize('view', $application);

        if (! $request->user()->can('update', $application)) {
            return redirect()->route('applicant.applications.show', $application);
        }

        if (ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
            return redirect()->route('applicant.applications.multiple.qualifications.create', $application);
        }

        try {
            ApplicantQualificationAmendmentGuard::assertWorkspaceAccessible($application, null, true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('applicant.applications.show', $application)
                ->withErrors($e->errors());
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return redirect()->route('applicant.applications.show', $application)
                ->withErrors(['application' => $e->getMessage()]);
        }

        return $this->buildQualificationWorkspaceInertiaResponse($request, $application, null);
    }

    public function editQualificationWorkspace(Request $request, Application $application, Qualification $qualification): Response|RedirectResponse
    {
        $this->authorize('view', $application);

        if ((int) $qualification->application_id !== (int) $application->id) {
            abort(404);
        }

        if (! $request->user()->can('update', $application)) {
            return redirect()->route('applicant.applications.show', $application);
        }

        if (ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
            return redirect()->route('applicant.applications.multiple.qualifications.edit', [
                'application' => $application,
                'qualification' => $qualification,
            ]);
        }

        try {
            ApplicantQualificationAmendmentGuard::assertWorkspaceAccessible($application, $qualification, false);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('applicant.applications.show', $application)
                ->withErrors($e->errors());
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return redirect()->route('applicant.applications.show', $application)
                ->withErrors(['application' => $e->getMessage()]);
        }

        return $this->buildQualificationWorkspaceInertiaResponse($request, $application, (int) $qualification->id);
    }

    private function buildEditInertiaResponse(Request $request, Application $application, ?int $amendmentQualificationId): Response
    {
        // Payment step UX: prepare invoice automatically when the applicant reaches the Payment step
        // (only if the application is ready for payment; do not throw on GET).
        $requestedStep = (string) $request->query('step', '');
        if ($requestedStep === 'payment' && $request->user()) {
            try {
                app(ApplicationSubmissionReadinessService::class)->assertReadyForPayment($application, $request->user());
                app(InvoiceService::class)->ensureInvoice($application, $request->user());
                $application->refresh();
            } catch (ValidationException $e) {
                // Not ready for payment yet — UI will show missing requirements.
            }
        }

        $application->load([
            'qualifications.subjectResults',
            'qualifications.certificates',
            'qualifications.country',
            'qualifications.awardingInstitution.country',
            'qualifications.qualificationTypeMaster.billingCategory',
            'qualifications.consentForm',
            'documents',
            'statusHistories',
            'invoice',
            'payments.proofDocument',
            'payments.latestAttempt',
        ]);
        $request->user()?->loadMissing(['applicantProfile', 'institutionProfile']);

        $now = now();
        $feeStructures = FeeStructure::query()
            ->where('is_active', true)
            ->where('effective_from', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $now);
            })
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('billing_category_id')
            ->map(fn ($group) => $group->first());

        $foreignCategory = BillingCategory::query()->where('code', 'FOREIGN_QUALIFICATIONS')->first();
        $foreignFeePreview = $foreignCategory && $feeStructures->get($foreignCategory->id)
            ? [
                'billing_category' => [
                    'id' => $foreignCategory->id,
                    'code' => $foreignCategory->code,
                    'name' => $foreignCategory->name,
                    'local_processing_days' => $foreignCategory->local_processing_days,
                    'foreign_processing_days' => $foreignCategory->foreign_processing_days,
                ],
                'fee_preview' => [
                    'currency' => $feeStructures->get($foreignCategory->id)->currency,
                    'local_fee_cents' => $feeStructures->get($foreignCategory->id)->local_fee_cents,
                    'foreign_fee_cents' => $feeStructures->get($foreignCategory->id)->foreign_fee_cents,
                    'effective_from' => optional($feeStructures->get($foreignCategory->id)->effective_from)?->toIso8601String(),
                ],
            ]
            : null;

        $certificateSubjects = CertificateSubject::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (CertificateSubject $s) => ['id' => $s->id, 'name' => $s->name])
            ->all();

        return Inertia::render('Applicant/Applications/Edit', [
            'application' => $this->applicationPayload($request, $application),
            'cgrate' => [
                'enabled' => (bool) config('cgrate.enabled'),
                'poll_interval_seconds' => (int) config('cgrate.poll_interval_seconds', 10),
                'payment_expiry_minutes' => (int) config('cgrate.payment_expiry_minutes', 10),
            ],
            'bankTransfer' => $this->bankTransferConfigPayload(),
            'certificateSubjects' => $certificateSubjects,
            'subjectGradeOptions' => CertificateSubjectGrade::allowed(),
            'serviceTypes' => array_map(
                fn (ServiceType $type) => ['value' => $type->value, 'label' => ucfirst($type->value)],
                ServiceType::cases(),
            ),
            'foreignFeePreview' => $foreignFeePreview,
            'qualificationTypes' => array_map(
                fn (QualificationType $t) => [
                    'id' => $t->id,
                    'zqf_level_code' => $t->zqf_level_code,
                    'level_label' => $t->level_label,
                    'name' => $t->name,
                    'requires_subject_results' => (bool) $t->requires_subject_results,
                    'billing_category' => [
                        'id' => $t->billingCategory?->id,
                        'code' => $t->billingCategory?->code,
                        'name' => $t->billingCategory?->name,
                        'local_processing_days' => $t->billingCategory?->local_processing_days,
                        'foreign_processing_days' => $t->billingCategory?->foreign_processing_days,
                    ],
                    'fee_preview' => $t->billingCategory?->id && $feeStructures->get($t->billingCategory->id)
                        ? [
                            'currency' => $feeStructures->get($t->billingCategory->id)->currency,
                            'local_fee_cents' => $feeStructures->get($t->billingCategory->id)->local_fee_cents,
                            'foreign_fee_cents' => $feeStructures->get($t->billingCategory->id)->foreign_fee_cents,
                            'effective_from' => optional($feeStructures->get($t->billingCategory->id)->effective_from)?->toIso8601String(),
                        ]
                        : null,
                ],
                QualificationType::query()
                    ->with('billingCategory')
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get()
                    ->all(),
            ),
            'countries' => $this->countryOptions(),
            'awardingInstitutions' => $this->awardingInstitutionOptions(),
            'localConsent' => (array) config('consent.local'),
            'declarationsCopy' => (array) config('applicant_wizard.declarations'),
            'applicant' => $this->applicantPayload($request),
            'amendmentQualificationId' => $amendmentQualificationId,
        ]);
    }

    private function buildQualificationWorkspaceInertiaResponse(Request $request, Application $application, ?int $qualificationId): Response
    {
        $application->load([
            'qualifications.subjectResults',
            'qualifications.certificates',
            'qualifications.country',
            'qualifications.awardingInstitution.country',
            'qualifications.qualificationTypeMaster.billingCategory',
            'qualifications.consentForm',
            'documents',
            'statusHistories',
            'invoice',
            'payments.proofDocument',
        ]);
        $request->user()?->loadMissing(['applicantProfile', 'institutionProfile']);

        $certificateSubjects = CertificateSubject::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (CertificateSubject $s) => ['id' => $s->id, 'name' => $s->name])
            ->all();

        $qualificationTypes = QualificationType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (QualificationType $t) => [
                'id' => $t->id,
                'zqf_level_code' => $t->zqf_level_code,
                'level_label' => $t->level_label,
                'name' => $t->name,
                'requires_subject_results' => (bool) $t->requires_subject_results,
            ])
            ->all();

        return Inertia::render('Applicant/Applications/Qualifications/Workspace', [
            'application' => $this->applicationPayload($request, $application),
            'qualificationId' => $qualificationId,
            'countries' => $this->countryOptions(),
            'qualificationTypes' => $qualificationTypes,
            'certificateSubjects' => $certificateSubjects,
            'subjectGradeOptions' => CertificateSubjectGrade::allowed(),
        ]);
    }

    public function update(UpdateApplicationDraftRequest $request, Application $application, ApplicationDraftService $drafts): RedirectResponse
    {
        $this->authorize('update', $application);

        $drafts->updateDraft($application, $request->user(), $request->validated());

        return back()->with('success', 'Draft updated.');
    }

    public function saveWizardDeclarations(SaveWizardDeclarationsRequest $request, Application $application, ApplicationDraftService $drafts): RedirectResponse
    {
        $this->authorize('update', $application);

        $drafts->saveWizardDeclarations($application, $request->user());

        return back()->with('success', 'Declarations saved.');
    }

    public function destroy(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('delete', $application);

        $applicationNumber = $application->application_number;
        Application::destroy((int) $application->id);

        return redirect()->route('applicant.applications.index')
            ->with('success', "Application {$applicationNumber} deleted.");
    }

    /**
     * @return array{deposit_account: array{bank_name: string, account_name: string, account_number: string, branch_code: string}}
     */
    public function bankTransferConfigPayload(): array
    {
        $account = (array) config('payments.bank_transfer.deposit_account', []);

        return [
            'deposit_account' => [
                'bank_name' => trim((string) ($account['bank_name'] ?? '')),
                'account_name' => trim((string) ($account['account_name'] ?? '')),
                'account_number' => trim((string) ($account['account_number'] ?? '')),
                'branch_code' => trim((string) ($account['branch_code'] ?? '')),
            ],
        ];
    }

    public function applicationPayload(Request $request, Application $application): array
    {
        $application = app(ApplicationQualificationOutcomeSyncService::class)->syncIfNeeded($application);
        $application->loadMissing('qualifications.certificates', 'payments', 'payments.latestAttempt', 'invoice');
        $qualificationLabels = app(ApplicantQualificationsService::class);

        $signedExpiry = now()->addMinutes(15);

        $documents = QualificationDocumentEvidence::filterActiveForReview($application->documents)
            ->sortByDesc('id')
            ->values()
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'qualification_id' => $doc->qualification_id,
                'document_type' => $doc->document_type?->value ?? (string) $doc->document_type,
                'original_name' => $doc->original_name,
                'mime_type' => $doc->mime_type,
                'size_bytes' => $doc->size_bytes,
                'version_number' => $doc->version_number,
                'is_current_version' => (bool) $doc->is_current_version,
                'created_at' => optional($doc->created_at)?->toIso8601String(),
                'preview_url' => URL::temporarySignedRoute('applicant.documents.preview', $signedExpiry, ['document' => $doc->id]),
                'download_url' => URL::temporarySignedRoute('applicant.documents.download', $signedExpiry, ['document' => $doc->id]),
            ]);

        $histories = $application->statusHistories
            ->sortByDesc('changed_at')
            ->values()
            ->map(fn ($history) => [
                'id' => $history->id,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'comment' => $history->comment,
                'changed_at' => optional($history->changed_at)?->toIso8601String(),
            ]);

        $paymentsSorted = $application->payments->sortByDesc('id');
        $displayPayment = $paymentsSorted->first(fn ($p) => $p->status === PaymentStatus::Confirmed)
            ?? $paymentsSorted->first();
        $paymentReviewPending = $paymentsSorted->contains(
            fn ($p) => $p->status === PaymentStatus::AwaitingFinanceReview
        );

        $paymentProof = $displayPayment?->proofDocument;

        $currentDocs = QualificationDocumentEvidence::filterActiveEvidence($application->documents);

        $sendBackHistoryByQualification = ApplicantQualificationAmendmentComments::historyGroupedByQualification(
            $application->qualifications,
        );

        $qualifications = $application->qualifications
            ->sortBy('id')
            ->values()
            ->map(function ($q) use ($application, $currentDocs, $sendBackHistoryByQualification, $qualificationLabels) {
                $qid = (int) $q->id;
                $commentHistory = $sendBackHistoryByQualification[$qid] ?? [];
                $latestComment = $commentHistory[0] ?? null;
                $instIso = strtoupper((string) (($q->awardingInstitution?->country?->iso_code) ?: ($q->country?->iso_code) ?: ''));
                $institutionIsForeign = $instIso !== '' && ! CountryIso::isZambia($instIso);
                $institutionHasConsentForm = (bool) ($q->awardingInstitution?->has_consent_form ?? false);
                $institutionConsentFormUrl = $q->awardingInstitution?->consent_form_url;

                $hasCert = $currentDocs->contains(fn ($d) => (int) ($d->qualification_id ?? 0) === $qid && (($d->document_type?->value ?? (string) $d->document_type) === 'certificate_copy'));
                $hasTranscript = $currentDocs->contains(fn ($d) => (int) ($d->qualification_id ?? 0) === $qid && (($d->document_type?->value ?? (string) $d->document_type) === 'transcript'));
                $hasForeignConsentSigned = $currentDocs->contains(fn ($d) => (int) ($d->qualification_id ?? 0) === $qid && (($d->document_type?->value ?? (string) $d->document_type) === 'consent_form_signed'))
                    || ($institutionIsForeign && (bool) ($q->consentForm?->uploaded_document_id));

                // Align with Vue modal + QualificationCapture: ZMB and ZM are Zambia (alpha-3 vs alpha-2 drift).
                $requiresForeignConsent = $institutionIsForeign;
                $hasForeignConsent = $requiresForeignConsent ? $hasForeignConsentSigned : false;
                // Local (Zambian awarding): institution consent upload / embedded acceptance not required per qualification.
                $hasLocalConsent = ! $requiresForeignConsent;

                $missing = [];
                if (! $hasCert) {
                    $missing[] = 'certificate_copy';
                }
                if ($requiresForeignConsent && ! $hasForeignConsent) {
                    $missing[] = 'foreign_consent';
                }

                $activeIssuedCertificate = $q->certificates
                    ->where('status', QualificationCertificate::STATUS_ISSUED)
                    ->sortByDesc('id')
                    ->first();

                $cveqCertificate = null;
                $rejectionNotice = null;
                $rejectionNoticeRecalled = false;

                if ($activeIssuedCertificate instanceof QualificationCertificate) {
                    if ($activeIssuedCertificate->isVerificationCertificate()) {
                        $cveqCertificate = [
                            'certificate_number' => $activeIssuedCertificate->certificate_number,
                            'issued_at' => optional($activeIssuedCertificate->issued_at)?->toIso8601String(),
                            'download_url' => route('applicant.applications.qualifications.certificate.download', [
                                'application' => $application,
                                'qualification' => $q,
                            ]),
                        ];
                    } elseif ($activeIssuedCertificate->isRejectionCertificate()) {
                        $rejectionNotice = [
                            'certificate_number' => $activeIssuedCertificate->certificate_number,
                            'issued_at' => optional($activeIssuedCertificate->issued_at)?->toIso8601String(),
                            'download_url' => route('applicant.applications.qualifications.rejection_notice.download', [
                                'application' => $application,
                                'qualification' => $q,
                            ]),
                        ];
                    }
                } elseif ($q->certificates
                    ->where('status', QualificationCertificate::STATUS_REVOKED)
                    ->contains(fn (QualificationCertificate $cert) => $cert->isRejectionCertificate())) {
                    $rejectionNoticeRecalled = true;
                }

                $institutionForm = QualificationAwardingInstitutionFormState::forForm($q);

                return [
                    'id' => $q->id,
                    'verification_reference_number' => $q->verification_reference_number,
                    'awarding_institution_id' => $institutionForm['awarding_institution_id'],
                    'awarding_institution_name' => $institutionForm['awarding_institution_name'],
                    'awarding_institution_name_other' => $institutionForm['awarding_institution_name_other'],
                    'awarding_institution' => $q->awardingInstitution
                        ? [
                            'id' => $q->awardingInstitution->id,
                            'name' => $q->awardingInstitution->name,
                            'country' => $q->awardingInstitution->country
                                ? ['id' => $q->awardingInstitution->country->id, 'iso_code' => $q->awardingInstitution->country->iso_code, 'name' => $q->awardingInstitution->country->name]
                                : null,
                            'is_zambian' => CountryIso::isZambia($instIso),
                            'is_foreign' => $institutionIsForeign,
                            'has_consent_form' => $institutionHasConsentForm,
                            'consent_form_url' => $institutionConsentFormUrl,
                        ]
                        : null,
                    'qualification_holder_name' => $q->qualification_holder_name,
                    'nrc_passport_number' => $q->nrc_passport_number,
                    'holder_identity' => $q->holder_identity ? (array) $q->holder_identity : null,
                    'country_id' => $q->country_id,
                    'country_name_other' => $q->country_name_other,
                    'country' => $q->country
                        ? ['id' => $q->country->id, 'iso_code' => $q->country->iso_code, 'name' => $q->country->name]
                        : null,
                    'certificate_number' => $q->certificate_number,
                    'student_number' => $q->student_number,
                    'examination_number' => $q->examination_number,
                    'title_of_qualification' => $q->title_of_qualification,
                    'names_as_on_qualification_document' => $q->names_as_on_qualification_document,
                    'qualification_title_id' => $q->qualification_title_id,
                    'applicant_entered_qualification_title' => $q->applicant_entered_qualification_title,
                    'verified_qualification_title' => $q->verified_qualification_title,
                    'qualification_title_source' => $q->qualification_title_source?->value ?? (string) ($q->qualification_title_source ?? ''),
                    'award_date' => optional($q->award_date)?->toDateString(),
                    'qualification_type' => (string) $q->qualification_type,
                    'qualification_type_id' => $q->qualification_type_id,
                    'qualification_type_master' => $q->qualificationTypeMaster
                        ? [
                            'id' => $q->qualificationTypeMaster->id,
                            'zqf_level_code' => $q->qualificationTypeMaster->zqf_level_code,
                            'level_label' => $q->qualificationTypeMaster->level_label,
                            'name' => $q->qualificationTypeMaster->name,
                            'requires_subject_results' => (bool) $q->qualificationTypeMaster->requires_subject_results,
                            'billing_category' => $q->qualificationTypeMaster->billingCategory
                                ? [
                                    'id' => $q->qualificationTypeMaster->billingCategory->id,
                                    'code' => $q->qualificationTypeMaster->billingCategory->code,
                                    'name' => $q->qualificationTypeMaster->billingCategory->name,
                                    'local_processing_days' => $q->qualificationTypeMaster->billingCategory->local_processing_days,
                                    'foreign_processing_days' => $q->qualificationTypeMaster->billingCategory->foreign_processing_days,
                                ]
                                : null,
                        ]
                        : null,
                    'transcript_required' => (bool) $q->transcript_required,
                    'transcript_reason' => $q->transcript_reason,
                    'notes' => $q->notes,
                    'is_foreign_qualification' => $institutionIsForeign,
                    'subject_results' => $q->subjectResults
                        ->sortBy('display_order')
                        ->values()
                        ->map(fn ($row) => [
                            'certificate_subject_id' => $row->certificate_subject_id,
                            'subject_name' => $row->subject_name,
                            'grade' => $row->grade,
                        ]),

                    // Status flags for UI badges
                    'has_certificate_document' => $hasCert,
                    'has_transcript_document' => $hasTranscript,
                    'requires_foreign_consent' => $requiresForeignConsent,
                    'has_foreign_consent' => $hasForeignConsent,
                    'has_local_consent' => $hasLocalConsent,
                    'institution_consent_form_url' => $requiresForeignConsent ? $institutionConsentFormUrl : null,
                    'institution_has_consent_form' => $requiresForeignConsent ? $institutionHasConsentForm : null,
                    'missing_requirements' => $missing,
                    'verification_state' => $q->verification_state?->value ?? (string) ($q->verification_state ?? ''),
                    'status_label' => $qualificationLabels->applicantStatusLabel($q, $application),
                    'returned_to_applicant_at' => optional($q->returned_to_applicant_at)?->toIso8601String(),
                    'send_back_reopen_level' => $q->send_back_reopen_level,
                    'amendment_comment' => $latestComment['body'] ?? null,
                    'latest_amendment_comment' => $latestComment,
                    'amendment_comments_count' => count($commentHistory),
                    'amendment_comment_history' => $commentHistory,
                    'cveq_certificate' => $cveqCertificate,
                    'rejection_notice' => $rejectionNotice,
                    'rejection_notice_recalled' => $rejectionNoticeRecalled,
                ];
            })
            ->all();

        $paymentSatisfaction = app(ApplicationPaymentSatisfaction::class);

        $openSupplementary = Invoice::query()
            ->where('application_id', $application->id)
            ->whereNotNull('supplementary_of_invoice_id')
            ->where('status', InvoiceStatus::Issued)
            ->whereNull('paid_at')
            ->orderByDesc('id')
            ->first();

        $appMeta = (array) ($application->metadata ?? []);

        return [
            'id' => $application->id,
            'uuid' => $application->uuid,
            'application_number' => $application->application_number,
            'payment_satisfied' => $paymentSatisfaction->isSatisfied($application),
            'payment_outstanding_cents' => $paymentSatisfaction->outstandingCents($application),
            'fee_amendment_overpayment_notice' => $appMeta['fee_amendment_overpayment_notice'] ?? null,
            'applicant_type' => $application->applicant_type?->value ?? (string) $application->applicant_type,
            'service_type' => $application->service_type?->value ?? (string) $application->service_type,
            'qualification_category' => $application->qualification_category,
            'current_status' => $application->current_status?->value ?? (string) $application->current_status,
            'status_label' => $application->applicantStatusLabel(),
            'display_status_label' => $application->applicantDisplayStatusLabel(),
            'correction_required' => $application->hasQualificationsAwaitingCorrection(),
            'correction_required_count' => ApplicantQualificationAmendmentGuard::returnedQualificationsCount($application),
            'correction_required_mode' => ApplicantQualificationAmendmentGuard::isRestrictedAmendmentMode($application),
            'is_foreign' => (bool) $application->is_foreign,
            'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
            'service_deadline_at' => optional($application->service_deadline_at)?->toIso8601String(),
            'paid_at' => optional($application->paid_at)?->toIso8601String(),
            'invoice' => $application->invoice
                ? $this->invoicePayloadForApplicant($application->invoice)
                : null,
            'supplementary_invoice' => $openSupplementary
                ? [
                    'id' => $openSupplementary->id,
                    'invoice_number' => $openSupplementary->invoice_number,
                    'currency' => $openSupplementary->currency,
                    'amount_cents' => $openSupplementary->amount_cents,
                    'status' => $openSupplementary->status?->value ?? (string) $openSupplementary->status,
                    'fee_label_snapshot' => $openSupplementary->fee_label_snapshot,
                    'issued_at' => optional($openSupplementary->issued_at)?->toIso8601String(),
                    'amendment_reason' => is_array($openSupplementary->metadata) ? ($openSupplementary->metadata['amendment_reason'] ?? null) : null,
                    'download_url' => route('applicant.invoices.download', $openSupplementary),
                ]
                : null,
            'payment' => $displayPayment
                ? [
                    'id' => $displayPayment->id,
                    'method' => $displayPayment->method?->value ?? (string) $displayPayment->method,
                    'status' => $displayPayment->status?->value ?? (string) $displayPayment->status,
                    'currency' => $displayPayment->currency,
                    'amount_cents' => $displayPayment->amount_cents,
                    'provider' => $displayPayment->provider,
                    'provider_reference' => $displayPayment->provider_reference,
                    'mobile_number' => $displayPayment->mobile_number,
                    'proof_document_id' => $displayPayment->proof_document_id,
                    'rejection_reason' => $displayPayment->rejection_reason,
                    'review_comment' => $displayPayment->review_comment,
                    'awaiting_finance_review_at' => optional($displayPayment->awaiting_finance_review_at)?->toIso8601String(),
                    'reviewed_at' => optional($displayPayment->reviewed_at)?->toIso8601String(),
                    'rejected_at' => optional($displayPayment->rejected_at)?->toIso8601String(),
                    'confirmed_at' => optional($displayPayment->confirmed_at)?->toIso8601String(),
                    'latest_attempt' => $displayPayment->latestAttempt
                        ? app(ApplicantPaymentAttemptStatusPresenter::class)->presentSummary(
                            $displayPayment->latestAttempt,
                            $displayPayment,
                        )
                        : null,
                    'proof_document' => $paymentProof
                        ? [
                            'id' => $paymentProof->id,
                            'original_name' => $paymentProof->original_name,
                            'preview_url' => URL::temporarySignedRoute('applicant.documents.preview', $signedExpiry, ['document' => $paymentProof->id]),
                            'download_url' => URL::temporarySignedRoute('applicant.documents.download', $signedExpiry, ['document' => $paymentProof->id]),
                        ]
                        : null,
                    'receipt_download_url' => app(PaymentReceiptPdfService::class)->receiptDownloadUrl($displayPayment, 'applicant.payments.receipt.download'),
                ]
                : null,
            'payment_review_pending' => $paymentReviewPending,
            'qualification' => $application->qualification
                ? [
                    'id' => $application->qualification->id,
                    'verification_reference_number' => $application->qualification->verification_reference_number,
                    'awarding_institution_id' => $application->qualification->awarding_institution_id,
                    'awarding_institution_name' => $application->qualification->awarding_institution_name,
                    'awarding_institution_name_other' => $application->qualification->awarding_institution_name_other,
                    'awarding_institution' => $application->qualification->awardingInstitution
                        ? [
                            'id' => $application->qualification->awardingInstitution->id,
                            'name' => $application->qualification->awardingInstitution->name,
                        ]
                        : null,
                    'qualification_holder_name' => $application->qualification->qualification_holder_name,
                    'country_id' => $application->qualification->country_id,
                    'country_name_other' => $application->qualification->country_name_other,
                    'country' => $application->qualification->country
                        ? [
                            'id' => $application->qualification->country->id,
                            'iso_code' => $application->qualification->country->iso_code,
                            'name' => $application->qualification->country->name,
                        ]
                        : null,
                    'nrc_passport_number' => $application->qualification->nrc_passport_number,
                    'certificate_number' => $application->qualification->certificate_number,
                    'student_number' => $application->qualification->student_number,
                    'examination_number' => $application->qualification->examination_number,
                    'title_of_qualification' => $application->qualification->title_of_qualification,
                    'names_as_on_qualification_document' => $application->qualification->names_as_on_qualification_document,
                    'award_date' => optional($application->qualification->award_date)?->toDateString(),
                    'qualification_type' => (string) $application->qualification->qualification_type,
                    'qualification_type_id' => $application->qualification->qualification_type_id,
                    'qualification_type_master' => $application->qualification->qualificationTypeMaster
                        ? [
                            'id' => $application->qualification->qualificationTypeMaster->id,
                            'zqf_level_code' => $application->qualification->qualificationTypeMaster->zqf_level_code,
                            'level_label' => $application->qualification->qualificationTypeMaster->level_label,
                            'name' => $application->qualification->qualificationTypeMaster->name,
                            'requires_subject_results' => (bool) $application->qualification->qualificationTypeMaster->requires_subject_results,
                            'billing_category' => $application->qualification->qualificationTypeMaster->billingCategory
                                ? [
                                    'id' => $application->qualification->qualificationTypeMaster->billingCategory->id,
                                    'code' => $application->qualification->qualificationTypeMaster->billingCategory->code,
                                    'name' => $application->qualification->qualificationTypeMaster->billingCategory->name,
                                    'local_processing_days' => $application->qualification->qualificationTypeMaster->billingCategory->local_processing_days,
                                    'foreign_processing_days' => $application->qualification->qualificationTypeMaster->billingCategory->foreign_processing_days,
                                ]
                                : null,
                        ]
                        : null,
                    'transcript_required' => (bool) $application->qualification->transcript_required,
                    'transcript_reason' => $application->qualification->transcript_reason,
                    'notes' => $application->qualification->notes,
                    'subject_results' => $application->qualification->subjectResults
                        ->sortBy('display_order')
                        ->values()
                        ->map(fn ($row) => [
                            'certificate_subject_id' => $row->certificate_subject_id,
                            'subject_name' => $row->subject_name,
                            'grade' => $row->grade,
                        ]),
                ]
                : null,
            'consent_form' => $application->consentForm
                ? [
                    'id' => $application->consentForm->id,
                    'consent_type' => $application->consentForm->consent_type?->value ?? (string) $application->consentForm->consent_type,
                    'embedded_text_version' => $application->consentForm->embedded_text_version,
                    'agreed_by_name' => $application->consentForm->agreed_by_name,
                    'agreed_at' => optional($application->consentForm->agreed_at)?->toIso8601String(),
                    'uploaded_document_id' => $application->consentForm->uploaded_document_id,
                    'zaqa_uploaded_document_id' => $application->consentForm->zaqa_uploaded_document_id,
                    'source_awarding_institution_name' => $application->consentForm->source_awarding_institution_name,
                    // Back-compat
                    'source_awarding_body_name' => $application->consentForm->source_awarding_institution_name,
                ]
                : null,
            'documents' => $documents,
            'status_histories' => $histories,
            'can_edit' => $request->user()->can('update', $application),
            'metadata' => (array) ($application->metadata ?? []),

            // Multi-qualification payload for the applicant wizard
            'qualifications' => $qualifications,

            'wizard_declarations' => $this->wizardDeclarationsPayload($application),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoicePayloadForApplicant(Invoice $invoice): array
    {
        $invoice->loadMissing('application.qualifications');
        $presenter = app(InvoiceDocumentPresenter::class);

        $lineItems = app(InvoicePdfService::class)
            ->lineItems($invoice)
            ->values()
            ->all();

        return array_merge([
            'id' => $invoice->id,
            'invoice_number' => $presenter->documentNumber($invoice),
            'currency' => $invoice->currency,
            'amount_cents' => $invoice->amount_cents,
            'status' => $invoice->status?->value ?? (string) $invoice->status,
            'fee_label_snapshot' => $invoice->fee_label_snapshot,
            'issued_at' => optional($invoice->issued_at)?->toIso8601String(),
            'paid_at' => optional($invoice->paid_at)?->toIso8601String(),
            'download_url' => route('applicant.invoices.download', $invoice),
            'line_items' => $lineItems,
        ], $presenter->applicantPayload($invoice));
    }

    public function wizardDeclarationsPayload(Application $application): array
    {
        $wd = (array) (($application->metadata ?? [])['wizard_declarations'] ?? []);

        return [
            'terms_accepted_at' => isset($wd['terms_accepted_at']) && is_string($wd['terms_accepted_at']) ? $wd['terms_accepted_at'] : null,
            'information_confirmed_at' => isset($wd['information_confirmed_at']) && is_string($wd['information_confirmed_at'])
                ? $wd['information_confirmed_at']
                : null,
        ];
    }

    public function applicantPayload(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'phone_secondary' => $user->phone_secondary,
            'applicant_type' => $user->applicant_type?->value ?? (string) $user->applicant_type,
            'applicant_profile' => $user->applicantProfile
                ? $user->applicantProfile->only([
                    'first_name',
                    'middle_name',
                    'surname',
                    'gender',
                    'nrc_number',
                    'passport_number',
                    'identity_type',
                    'email',
                    'phone_primary',
                    'phone_secondary',
                    'identity_document_original_name',
                    'identity_document_uploaded_at',
                    'identity_document_size_bytes',
                ])
                : null,
            'institution_profile' => $user->institutionProfile
                ? $user->institutionProfile->only([
                    'institution_name',
                    'email',
                    'phone_primary',
                    'phone_secondary',
                    'tpin',
                    'contact_person_name',
                ])
                : null,
        ];
    }

    public function countryOptions(): array
    {
        return Country::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'iso_code', 'name'])
            ->map(fn (Country $country) => ['id' => $country->id, 'iso_code' => $country->iso_code, 'name' => $country->name])
            ->all();
    }

    public function awardingInstitutionOptions(): array
    {
        return AwardingInstitution::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AwardingInstitution $institution) => ['id' => $institution->id, 'name' => $institution->name])
            ->all();
    }
}
