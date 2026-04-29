<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Applications\ApplicationDraftService;
use App\Domain\Applications\ApplicationSubmissionService;
use App\Enums\PaymentStatus;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\CreateApplicationDraftRequest;
use App\Http\Requests\Applicant\UpdateApplicationDraftRequest;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\BillingCategory;
use App\Models\Country;
use App\Models\FeeStructure;
use App\Models\QualificationType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantApplicationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $applications = Application::query()
            ->where('applicant_user_id', $user->id)
            ->with([
                'qualification.subjectResults',
                'documents',
                'consentForm',
            ])
            ->latest('id')
            ->get()
            ->map(fn (Application $application) => [
                'id' => $application->id,
                'uuid' => $application->uuid,
                'application_number' => $application->application_number,
                'current_status' => $application->current_status?->value ?? (string) $application->current_status,
                'status_label' => $application->applicantStatusLabel(),
                'service_type' => $application->service_type?->value ?? (string) $application->service_type,
                'qualification_category' => $application->qualification_category,
                'is_foreign' => (bool) $application->is_foreign,
                'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
                'created_at' => optional($application->created_at)?->toIso8601String(),
                'can_edit' => $request->user()->can('update', $application),
                'can_delete' => $request->user()->can('delete', $application),
                'wizard' => $this->wizardSummary($application),
            ]);

        return Inertia::render('Applicant/Applications/Index', [
            'applications' => $applications,
        ]);
    }

    private function wizardSummary(Application $application): array
    {
        $qualification = $application->qualification;

        $needsSubjects = (bool) ($qualification?->qualificationTypeMaster?->requires_subject_results ?? false);

        $qualificationOk = (bool) $qualification
            && trim((string) ($qualification->qualification_holder_name ?? '')) !== ''
            && trim((string) ($qualification->nrc_passport_number ?? '')) !== ''
            && (bool) $qualification->country_id
            && ((bool) $qualification->awarding_institution_id || trim((string) $qualification->awarding_institution_name_other) !== '')
            && trim((string) $qualification->title_of_qualification) !== ''
            && ! empty($qualification->award_date)
            && (int) ($qualification->qualification_type_id ?? 0) > 0
            && (
                trim((string) $qualification->certificate_number) !== ''
                || trim((string) $qualification->student_number) !== ''
                || trim((string) $qualification->examination_number) !== ''
            );

        $subjectsOk = ! $needsSubjects
            || (($qualification?->subjectResults?->count() ?? 0) > 0);

        $hasNrc = $application->documents->contains(fn ($d) => ($d->document_type?->value ?? (string) $d->document_type) === 'nrc_copy' && (bool) $d->is_current_version);
        $hasCert = $application->documents->contains(fn ($d) => ($d->document_type?->value ?? (string) $d->document_type) === 'certificate_copy' && (bool) $d->is_current_version);
        $hasTranscript = $application->documents->contains(fn ($d) => ($d->document_type?->value ?? (string) $d->document_type) === 'transcript' && (bool) $d->is_current_version);
        $documentsOk = $hasNrc && $hasCert && ((bool) $application->is_foreign ? $hasTranscript : true);

        $consentOk = (bool) $application->is_foreign
            ? (bool) ($application->consentForm?->uploaded_document_id) && (bool) ($application->consentForm?->zaqa_uploaded_document_id)
            : (bool) ($application->consentForm?->agreed_at);

        $steps = [
            ['key' => 'applicant', 'label' => 'Applicant', 'done' => true],
            ['key' => 'qualification', 'label' => 'Qualification', 'done' => $qualificationOk],
            ['key' => 'subjects', 'label' => 'Subjects', 'done' => $subjectsOk, 'enabled' => $needsSubjects],
            ['key' => 'documents', 'label' => 'Documents', 'done' => $documentsOk],
            ['key' => 'consent', 'label' => 'Consent', 'done' => $consentOk],
            ['key' => 'review', 'label' => 'Review & submit', 'done' => $qualificationOk && $subjectsOk && $documentsOk && $consentOk],
        ];

        $filtered = array_values(array_filter($steps, fn ($s) => ($s['enabled'] ?? true) === true));
        $currentIndex = 0;
        foreach ($filtered as $idx => $s) {
            if (! (bool) $s['done']) {
                $currentIndex = $idx;
                break;
            }
            $currentIndex = $idx;
        }

        $current = $filtered[$currentIndex] ?? ['key' => 'review', 'label' => 'Review & submit', 'done' => false];

        return [
            'current_step' => [
                'index' => $currentIndex + 1,
                'total' => count($filtered),
                'key' => $current['key'],
                'label' => $current['label'],
                'done' => (bool) $current['done'],
            ],
        ];
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

    public function store(CreateApplicationDraftRequest $request, ApplicationDraftService $drafts): RedirectResponse
    {
        $validated = $request->validated();
        $validated['service_type'] = ServiceType::Verification->value;

        $application = $drafts->createDraft($request->user(), $validated);

        return redirect()->route('applicant.applications.edit', ['application' => $application, 'step' => 'qualification'])
            ->with('success', 'Draft created. Please complete the application details.');
    }

    public function show(Request $request, Application $application): Response
    {
        $this->authorize('view', $application);

        $application->load(['qualification.subjectResults', 'qualification.country', 'qualification.awardingInstitution', 'qualification.qualificationTypeMaster.billingCategory', 'documents', 'consentForm', 'statusHistories', 'invoice', 'payments.proofDocument']);
        $request->user()?->loadMissing(['applicantProfile', 'institutionProfile']);

        return Inertia::render('Applicant/Applications/Show', [
            'application' => $this->applicationPayload($request, $application),
            'countries' => $this->countryOptions(),
            'awardingInstitutions' => $this->awardingInstitutionOptions(),
            'localConsent' => (array) config('consent.local'),
            'applicant' => $this->applicantPayload($request),
        ]);
    }

    public function edit(Request $request, Application $application): Response|RedirectResponse
    {
        $this->authorize('view', $application);

        if (! $request->user()->can('update', $application)) {
            return redirect()->route('applicant.applications.show', $application);
        }

        $application->load(['qualification.subjectResults', 'qualification.country', 'qualification.awardingInstitution', 'qualification.qualificationTypeMaster.billingCategory', 'documents', 'consentForm', 'statusHistories', 'invoice', 'payments.proofDocument']);
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

        return Inertia::render('Applicant/Applications/Edit', [
            'application' => $this->applicationPayload($request, $application),
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
            'applicant' => $this->applicantPayload($request),
        ]);
    }

    public function update(UpdateApplicationDraftRequest $request, Application $application, ApplicationDraftService $drafts): RedirectResponse
    {
        $this->authorize('update', $application);

        $drafts->updateDraft($application, $request->user(), $request->validated());

        return back()->with('success', 'Draft updated.');
    }

    public function submit(Request $request, Application $application, ApplicationSubmissionService $submission): RedirectResponse
    {
        $this->authorize('submit', $application);

        $submission->submit($application, $request->user());

        return redirect()->route('applicant.applications.feedback.show', $application)
            ->with('success', 'Application submitted successfully.');
    }

    public function destroy(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('delete', $application);

        $applicationNumber = $application->application_number;
        $application->delete();

        return redirect()->route('applicant.applications.index')
            ->with('success', "Application {$applicationNumber} deleted.");
    }

    private function applicationPayload(Request $request, Application $application): array
    {
        $signedExpiry = now()->addMinutes(15);

        $documents = $application->documents
            ->sortByDesc('id')
            ->values()
            ->map(fn ($doc) => [
                'id' => $doc->id,
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

        $paymentProof = $displayPayment?->proofDocument;

        return [
            'id' => $application->id,
            'uuid' => $application->uuid,
            'application_number' => $application->application_number,
            'applicant_type' => $application->applicant_type?->value ?? (string) $application->applicant_type,
            'service_type' => $application->service_type?->value ?? (string) $application->service_type,
            'qualification_category' => $application->qualification_category,
            'current_status' => $application->current_status?->value ?? (string) $application->current_status,
            'status_label' => $application->applicantStatusLabel(),
            'is_foreign' => (bool) $application->is_foreign,
            'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
            'service_deadline_at' => optional($application->service_deadline_at)?->toIso8601String(),
            'paid_at' => optional($application->paid_at)?->toIso8601String(),
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
                    'confirmed_at' => optional($displayPayment->confirmed_at)?->toIso8601String(),
                    'proof_document' => $paymentProof
                        ? [
                            'id' => $paymentProof->id,
                            'original_name' => $paymentProof->original_name,
                            'preview_url' => URL::temporarySignedRoute('applicant.documents.preview', $signedExpiry, ['document' => $paymentProof->id]),
                            'download_url' => URL::temporarySignedRoute('applicant.documents.download', $signedExpiry, ['document' => $paymentProof->id]),
                        ]
                        : null,
                ]
                : null,
            'qualification' => $application->qualification
                ? [
                    'id' => $application->qualification->id,
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
                    'source_awarding_institution_name' => $application->consentForm->source_awarding_body_name,
                    // Back-compat
                    'source_awarding_body_name' => $application->consentForm->source_awarding_body_name,
                ]
                : null,
            'documents' => $documents,
            'status_histories' => $histories,
            'can_edit' => $request->user()->can('update', $application),
            'metadata' => (array) ($application->metadata ?? []),
        ];
    }

    private function applicantPayload(Request $request): array
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
                    'nrc_number',
                    'passport_number',
                    'email',
                    'phone_primary',
                    'phone_secondary',
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

    private function countryOptions(): array
    {
        return Country::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'iso_code', 'name'])
            ->map(fn (Country $country) => ['id' => $country->id, 'iso_code' => $country->iso_code, 'name' => $country->name])
            ->all();
    }

    private function awardingInstitutionOptions(): array
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
