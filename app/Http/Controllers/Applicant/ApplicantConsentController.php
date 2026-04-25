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

        if ((bool) $application->is_foreign) {
            throw ValidationException::withMessages([
                'consent' => 'Local embedded consent is not available for foreign applications.',
            ]);
        }

        $before = $application->consentForm?->toArray();

        DB::transaction(function () use ($request, $application, $audit, $lifecycle, $before) {
            $consent = ConsentForm::updateOrCreate(
                ['application_id' => $application->id],
                [
                    'consent_type' => ConsentType::LocalEmbedded,
                    'embedded_text_version' => (string) config('consent.local.version'),
                    'agreed_by_name' => (string) $request->validated()['agreed_by_name'],
                    'agreed_at' => now(),
                    'uploaded_document_id' => null,
                    'source_awarding_body_name' => null,
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
                    'embedded_text_version' => $consent->embedded_text_version,
                ],
                actor: $request->user(),
            );

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

        $before = $application->consentForm?->toArray();

        DB::transaction(function () use ($request, $application, $documents, $audit, $lifecycle, $file, $before) {
            $document = $documents->upload($application, DocumentType::ConsentFormSigned, $file, $request->user());

            $consent = ConsentForm::updateOrCreate(
                ['application_id' => $application->id],
                [
                    'consent_type' => (bool) $application->is_foreign ? ConsentType::ForeignUploaded : ConsentType::LocalEmbedded,
                    'embedded_text_version' => (bool) $application->is_foreign ? null : (string) config('consent.local.version'),
                    'agreed_by_name' => (string) ($application->consentForm?->agreed_by_name ?: $request->user()->name),
                    'agreed_at' => $application->consentForm?->agreed_at ?: now(),
                    'uploaded_document_id' => $document->id,
                    'source_awarding_body_name' => $request->validated()['source_awarding_institution_name']
                        ?? $request->validated()['source_awarding_body_name']
                        ?? null,
                ],
            );

            $audit->record(
                eventType: (bool) $application->is_foreign ? 'consent.foreign_uploaded' : 'consent.optional_uploaded',
                module: 'Consent',
                actionName: (bool) $application->is_foreign ? 'foreign_uploaded' : 'optional_uploaded',
                message: (bool) $application->is_foreign ? 'Foreign signed consent form uploaded.' : 'Optional signed consent form uploaded.',
                entityType: ConsentForm::class,
                entityId: $consent->id,
                beforeState: $before,
                afterState: $consent->toArray(),
                metadata: [
                    'application_id' => $application->id,
                    'document_id' => $document->id,
                ],
                actor: $request->user(),
            );

            $lifecycle->milestone(
                application: $application,
                eventType: 'wizard',
                eventCode: 'wizard.step4.consent_signed_uploaded',
                stage: LifecycleStage::Wizard,
                title: 'Signed consent uploaded',
                description: (bool) $application->is_foreign
                    ? 'Applicant uploaded signed consent for foreign application.'
                    : 'Applicant uploaded optional signed consent.',
                visibility: LifecycleVisibility::Both,
                actor: $request->user(),
                metadata: [
                    'document_id' => $consent->uploaded_document_id,
                ],
                occurredAt: now(),
            );
        });

        return back()->with('success', 'Signed consent form uploaded.');
    }
}

