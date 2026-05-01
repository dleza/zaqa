<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Audit\AuditLogService;
use App\Domain\Documents\ApplicantDocumentService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\ConsentType;
use App\Enums\DocumentType;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\AcceptLocalConsentRequest;
use App\Http\Requests\Applicant\UploadForeignConsentRequest;
use App\Models\Application;
use App\Models\ConsentForm;
use App\Models\Qualification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicantConsentController extends Controller
{
    public function acceptLocal(
        AcceptLocalConsentRequest $request,
        Application $application,
        AuditLogService $audit,
        ApplicationLifecycleService $lifecycle,
    ): RedirectResponse {
        $this->authorize('update', $application);

        $application->loadMissing('qualifications');

        if ($application->qualifications->count() < 1) {
            throw ValidationException::withMessages([
                'consent' => 'Please add at least one qualification before accepting consent.',
            ]);
        }

        DB::transaction(function () use ($request, $application, $audit, $lifecycle) {
            // Local embedded consent is recorded per qualification item (even if identical text),
            // so mixed-local/foreign applications can enforce foreign consent per item independently.
            foreach ($application->qualifications as $qualification) {
                /** @var Qualification $qualification */
                $before = $qualification->consentForm?->toArray();
                $consent = ConsentForm::updateOrCreate(
                    ['qualification_id' => $qualification->id],
                    [
                        'consent_type' => ConsentType::LocalEmbedded,
                        'embedded_text_version' => (string) config('consent.local.version'),
                        'agreed_by_name' => (string) $request->validated()['agreed_by_name'],
                        'agreed_at' => now(),
                        'uploaded_document_id' => null,
                        'source_awarding_institution_name' => null,
                    ],
                );

                $audit->record(
                    eventType: 'consent.local_accepted',
                    module: 'Consent',
                    actionName: 'local_accepted',
                    message: 'Local embedded consent accepted.',
                    entityType: ConsentForm::class,
                    entityId: $consent->id,
                    beforeState: $before,
                    afterState: $consent->toArray(),
                    metadata: [
                        'application_id' => $application->id,
                        'qualification_id' => $qualification->id,
                        'embedded_text_version' => $consent->embedded_text_version,
                    ],
                    actor: $request->user(),
                );
            }

            $lifecycle->milestone(
                application: $application,
                eventType: 'wizard',
                eventCode: 'wizard.step4.consent_accepted',
                stage: LifecycleStage::Wizard,
                title: 'Consent accepted',
                description: 'Applicant accepted the embedded consent statement.',
                visibility: LifecycleVisibility::Both,
                actor: $request->user(),
                metadata: [
                    'consent_type' => 'local_embedded',
                    'version' => (string) config('consent.local.version'),
                ],
                occurredAt: now(),
            );
        });

        return back()->with('success', 'Consent accepted.');
    }

    public function uploadForeign(
        UploadForeignConsentRequest $request,
        Application $application,
        ApplicantDocumentService $documents,
        AuditLogService $audit,
        ApplicationLifecycleService $lifecycle,
    ): RedirectResponse {
        $this->authorize('update', $application);

        // Allow optional signed consent upload for local applications too (non-blocking).
        // For foreign applications, this upload may be required by business rules.

        $file = $request->file('file');
        $qualificationId = (int) ($request->validated()['qualification_id'] ?? 0);
        $qualification = Qualification::query()->whereKey($qualificationId)->firstOrFail();
        if ($qualification->application_id !== $application->id) {
            throw ValidationException::withMessages([
                'qualification_id' => 'Selected qualification does not belong to this application.',
            ]);
        }

        $before = $qualification->consentForm?->toArray();

        DB::transaction(function () use ($request, $application, $qualification, $documents, $audit, $lifecycle, $file, $before) {
            $awardingInstitutionDocument = $documents->upload($application, DocumentType::ConsentFormSigned, $file, $request->user(), $qualification);

            $consent = ConsentForm::updateOrCreate(
                ['qualification_id' => $qualification->id],
                [
                    'consent_type' => (bool) $qualification->is_foreign_qualification ? ConsentType::ForeignUploaded : ConsentType::LocalEmbedded,
                    'embedded_text_version' => (bool) $qualification->is_foreign_qualification ? null : (string) config('consent.local.version'),
                    'agreed_by_name' => (string) ($qualification->consentForm?->agreed_by_name ?: $request->user()->name),
                    'agreed_at' => $qualification->consentForm?->agreed_at ?: now(),
                    'uploaded_document_id' => $awardingInstitutionDocument->id,
                    'zaqa_uploaded_document_id' => null,
                    'source_awarding_institution_name' => $request->validated()['source_awarding_institution_name']
                        ?? $request->validated()['source_awarding_body_name']
                        ?? null,
                ],
            );

            $audit->record(
                eventType: (bool) $qualification->is_foreign_qualification ? 'consent.foreign_uploaded' : 'consent.optional_uploaded',
                module: 'Consent',
                actionName: (bool) $qualification->is_foreign_qualification ? 'foreign_uploaded' : 'optional_uploaded',
                message: (bool) $qualification->is_foreign_qualification ? 'Foreign signed consent form uploaded.' : 'Optional signed consent form uploaded.',
                entityType: ConsentForm::class,
                entityId: $consent->id,
                beforeState: $before,
                afterState: $consent->toArray(),
                metadata: [
                    'application_id' => $application->id,
                    'qualification_id' => $qualification->id,
                    'document_id' => $awardingInstitutionDocument->id,
                ],
                actor: $request->user(),
            );

            $lifecycle->milestone(
                application: $application,
                eventType: 'wizard',
                eventCode: 'wizard.step4.consent_signed_uploaded',
                stage: LifecycleStage::Wizard,
                title: 'Signed consent uploaded',
                description: (bool) $qualification->is_foreign_qualification
                    ? 'Applicant uploaded signed consent for foreign application.'
                    : 'Applicant uploaded optional signed consent.',
                visibility: LifecycleVisibility::Both,
                actor: $request->user(),
                metadata: [
                    'document_id' => $consent->uploaded_document_id,
                    'qualification_id' => $qualification->id,
                ],
                occurredAt: now(),
            );
        });

        return back()->with('success', 'Signed consent form uploaded.');
    }
}

