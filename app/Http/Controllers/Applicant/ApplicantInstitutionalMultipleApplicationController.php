<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Applications\ApplicationSubmissionReadinessService;
use App\Domain\Applications\InstitutionalMultipleApplicationDraftService;
use App\Domain\Applications\InstitutionalMultipleWizardService;
use App\Domain\Payments\InvoiceService;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\SaveWizardDeclarationsRequest;
use App\Http\Requests\Applicant\UpdateInstitutionalMultipleApplicationRequest;
use App\Models\Application;
use App\Models\BillingCategory;
use App\Models\CertificateSubject;
use App\Models\FeeStructure;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Support\Applications\ApplicationSubmissionMode;
use App\Support\Qualifications\CertificateSubjectGrade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantInstitutionalMultipleApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicantApplicationController $applicationPages,
    ) {}
    public function create(Request $request): Response
    {
        return Inertia::render('Applicant/Applications/Multiple/New');
    }

    public function store(Request $request, InstitutionalMultipleApplicationDraftService $drafts): RedirectResponse
    {
        $application = $drafts->createDraft($request->user(), $request->all());

        return redirect()->route('applicant.applications.multiple.edit', $application);
    }

    public function edit(Request $request, Application $application): Response|RedirectResponse
    {
        $this->authorize('view', $application);
        $this->assertInstitutionalMultiple($application);

        if (! $request->user()->can('update', $application)) {
            return redirect()->route('applicant.applications.show', $application);
        }

        $requestedStep = (string) $request->query('step', '');
        if ($requestedStep === 'payment' && $request->user()) {
            try {
                app(ApplicationSubmissionReadinessService::class)->assertReadyForPayment($application, $request->user());
                app(InvoiceService::class)->ensureInvoice($application, $request->user());
                $application->refresh();
            } catch (ValidationException) {
                // UI shows missing requirements.
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

        $wizard = app(InstitutionalMultipleWizardService::class);
        $reviewMissing = $wizard->missingItemsForReview($application);

        return Inertia::render('Applicant/Applications/Multiple/Edit', [
            'application' => $this->applicationPages->applicationPayload($request, $application),
            'cgrate' => [
                'enabled' => (bool) config('cgrate.enabled'),
                'poll_interval_seconds' => (int) config('cgrate.poll_interval_seconds', 10),
                'payment_expiry_minutes' => (int) config('cgrate.payment_expiry_minutes', 10),
            ],
            'bankTransfer' => $this->applicationPages->bankTransferConfigPayload(),
            'certificateSubjects' => CertificateSubject::query()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (CertificateSubject $s) => ['id' => $s->id, 'name' => $s->name])
                ->all(),
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
            'countries' => $this->applicationPages->countryOptions(),
            'awardingInstitutions' => $this->applicationPages->awardingInstitutionOptions(),
            'localConsent' => (array) config('consent.local'),
            'declarationsCopy' => (array) config('applicant_wizard.declarations'),
            'applicant' => $this->applicationPages->applicantPayload($request),
            'review_missing_items' => $reviewMissing,
            'initial_step' => $requestedStep !== '' ? $requestedStep : null,
        ]);
    }

    public function update(
        UpdateInstitutionalMultipleApplicationRequest $request,
        Application $application,
        InstitutionalMultipleApplicationDraftService $drafts,
    ): RedirectResponse {
        $this->authorize('update', $application);
        $this->assertInstitutionalMultiple($application);

        $drafts->updateApplicationInfo($application, $request->validated(), $request->user());

        return redirect()
            ->route('applicant.applications.multiple.edit', [
                'application' => $application,
                'step' => 'qualification_records',
            ])
            ->with('success', 'Application information saved.');
    }

    public function saveDeclarations(
        SaveWizardDeclarationsRequest $request,
        Application $application,
    ): RedirectResponse {
        $this->authorize('update', $application);
        $this->assertInstitutionalMultiple($application);

        app(\App\Domain\Applications\ApplicationDraftService::class)
            ->saveWizardDeclarations($application, $request->user());

        return back()->with('success', 'Declarations saved.');
    }

    public function createQualificationWorkspace(Request $request, Application $application): Response|RedirectResponse
    {
        $this->authorize('view', $application);
        $this->assertInstitutionalMultiple($application);

        if (! $request->user()->can('update', $application)) {
            return redirect()->route('applicant.applications.show', $application);
        }

        return $this->buildInstitutionalQualificationWorkspaceResponse($request, $application, null);
    }

    public function editQualificationWorkspace(
        Request $request,
        Application $application,
        Qualification $qualification,
    ): Response|RedirectResponse {
        $this->authorize('view', $application);
        $this->assertInstitutionalMultiple($application);

        if ((int) $qualification->application_id !== (int) $application->id) {
            abort(404);
        }

        if (! $request->user()->can('update', $application)) {
            return redirect()->route('applicant.applications.show', $application);
        }

        return $this->buildInstitutionalQualificationWorkspaceResponse($request, $application, (int) $qualification->id);
    }

    private function buildInstitutionalQualificationWorkspaceResponse(
        Request $request,
        Application $application,
        ?int $qualificationId,
    ): Response {
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

        return Inertia::render('Applicant/Applications/Multiple/QualificationWorkspace', [
            'application' => $this->applicationPages->applicationPayload($request, $application),
            'qualificationId' => $qualificationId,
            'countries' => $this->applicationPages->countryOptions(),
            'qualificationTypes' => $qualificationTypes,
            'certificateSubjects' => CertificateSubject::query()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (CertificateSubject $s) => ['id' => $s->id, 'name' => $s->name])
                ->all(),
            'subjectGradeOptions' => CertificateSubjectGrade::allowed(),
        ]);
    }

    private function assertInstitutionalMultiple(Application $application): void
    {
        if (! ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
            abort(404);
        }
    }
}
