<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Documents\ApplicantDocumentService;
use App\Enums\DocumentType;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminQualificationDocumentService
{
    public function __construct(
        private readonly ApplicantDocumentService $documents,
        private readonly AuditLogService $audit,
    ) {}

    public function upload(
        Qualification $qualification,
        DocumentType $documentType,
        UploadedFile $file,
        User $actor,
        ?string $correctionNote = null,
    ): QualificationDocument {
        return DB::transaction(function () use ($qualification, $documentType, $file, $actor, $correctionNote) {
            $qualification->refresh();
            $workflowSnapshot = $this->workflowSnapshot($qualification);

            $application = $qualification->application;
            if (! $application) {
                throw ValidationException::withMessages([
                    'file' => 'Application is missing for this qualification.',
                ]);
            }

            $qualificationScoped = $this->isQualificationScopedType($documentType);
            $targetQualification = $qualificationScoped ? $qualification : null;

            if ($qualificationScoped) {
                $this->assertDocumentBelongsToQualificationScope($qualification, $documentType);
            }

            $beforeCurrent = QualificationDocument::query()
                ->where('application_id', $application->id)
                ->where('qualification_id', $targetQualification?->id)
                ->where('document_type', $documentType->value)
                ->where('is_current_version', true)
                ->first();

            if ($beforeCurrent) {
                VerificationApplicantDocumentGuard::assertOfficerMayModifyDocument($actor, $beforeCurrent, $application);
            }

            $document = $this->documents->upload(
                $application,
                $documentType,
                $file,
                $actor,
                $targetQualification,
            );

            $qualification->refresh();
            $this->assertWorkflowUnchanged($qualification, $workflowSnapshot);

            $note = trim((string) ($correctionNote ?? ''));
            $this->audit->record(
                eventType: 'verification.qualification_document_uploaded',
                module: 'Verification',
                actionName: 'qualification_document_uploaded',
                message: $beforeCurrent
                    ? 'Verifier replaced a supporting document during qualification review.'
                    : 'Verifier uploaded a supporting document during qualification review.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $beforeCurrent ? [
                    'document_id' => $beforeCurrent->id,
                    'document_type' => $beforeCurrent->document_type?->value ?? (string) $beforeCurrent->document_type,
                    'original_name' => $beforeCurrent->original_name,
                    'version_number' => $beforeCurrent->version_number,
                ] : null,
                afterState: [
                    'document_id' => $document->id,
                    'document_type' => $document->document_type?->value ?? (string) $document->document_type,
                    'original_name' => $document->original_name,
                    'version_number' => $document->version_number,
                ],
                metadata: [
                    'application_id' => $application->id,
                    'qualification_id' => $qualification->id,
                    'correction_note' => $note !== '' ? $note : null,
                    'source' => 'admin_verification_edit',
                ],
                actor: $actor,
            );

            return $document;
        });
    }

    public function delete(Qualification $qualification, QualificationDocument $document, User $actor, ?string $correctionNote = null): void
    {
        DB::transaction(function () use ($qualification, $document, $actor, $correctionNote) {
            $qualification->refresh();
            $workflowSnapshot = $this->workflowSnapshot($qualification);

            $this->assertDocumentAccessibleForQualification($qualification, $document);

            $application = $qualification->application;
            if ($application) {
                VerificationApplicantDocumentGuard::assertOfficerMayModifyDocument($actor, $document, $application);
            }

            $before = [
                'document_id' => $document->id,
                'document_type' => $document->document_type?->value ?? (string) $document->document_type,
                'original_name' => $document->original_name,
                'version_number' => $document->version_number,
                'qualification_id' => $document->qualification_id,
            ];

            $this->documents->delete($document, $actor);

            $qualification->refresh();
            $this->assertWorkflowUnchanged($qualification, $workflowSnapshot);

            $note = trim((string) ($correctionNote ?? ''));
            $this->audit->record(
                eventType: 'verification.qualification_document_deleted',
                module: 'Verification',
                actionName: 'qualification_document_deleted',
                message: 'Verifier removed a supporting document during qualification review.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $before,
                afterState: null,
                metadata: [
                    'application_id' => $qualification->application_id,
                    'qualification_id' => $qualification->id,
                    'correction_note' => $note !== '' ? $note : null,
                    'source' => 'admin_verification_edit',
                ],
                actor: $actor,
            );
        });
    }

    private function isQualificationScopedType(DocumentType $documentType): bool
    {
        return in_array($documentType, [
            DocumentType::CertificateCopy,
            DocumentType::Transcript,
            DocumentType::ConsentFormSigned,
            DocumentType::ZaqaConsentFormSigned,
            DocumentType::OtherSupportingDocument,
        ], true);
    }

    private function assertDocumentBelongsToQualificationScope(Qualification $qualification, DocumentType $documentType): void
    {
        // Qualification-scoped uploads always target this qualification row.
    }

    public function assertDocumentAccessibleForQualification(Qualification $qualification, QualificationDocument $document): void
    {
        if ((int) $document->application_id !== (int) $qualification->application_id) {
            throw ValidationException::withMessages([
                'document' => 'This document does not belong to the qualification application.',
            ]);
        }

        $type = $document->document_type;
        if ($type === DocumentType::NrcCopy || $type === DocumentType::PassportCopy) {
            if ($document->qualification_id !== null) {
                throw ValidationException::withMessages([
                    'document' => 'This identity document cannot be managed from this qualification.',
                ]);
            }

            return;
        }

        if ($this->isQualificationScopedType($type)) {
            if ((int) ($document->qualification_id ?? 0) !== (int) $qualification->id) {
                throw ValidationException::withMessages([
                    'document' => 'This document belongs to a different qualification on the application.',
                ]);
            }

            return;
        }

        throw ValidationException::withMessages([
            'document' => 'This document type cannot be managed from qualification edit.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowSnapshot(Qualification $qualification): array
    {
        return [
            'verification_state' => $qualification->verification_state?->value ?? (string) ($qualification->verification_state ?? ''),
            'assigned_verifier_id' => $qualification->assigned_verifier_id,
            'verification_reference_number' => $qualification->verification_reference_number,
            'fee_amount_cents' => $qualification->fee_amount_cents,
            'fee_currency' => $qualification->fee_currency,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function assertWorkflowUnchanged(Qualification $qualification, array $before): void
    {
        $after = $this->workflowSnapshot($qualification);
        if ($after !== $before) {
            throw ValidationException::withMessages([
                'document' => 'Document change would have altered verification workflow state.',
            ]);
        }
    }
}
