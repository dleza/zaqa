<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Documents\ApplicantDocumentService;
use App\Domain\Documents\QualificationDocumentEvidence;
use App\Domain\Verification\VerificationQualificationAccess;
use App\Http\Controllers\Controller;
use App\Models\QualificationDocument;
use Illuminate\Http\Request;

class AdminVerificationDocumentController extends Controller
{
    public function preview(Request $request, QualificationDocument $document, ApplicantDocumentService $documents, AuditLogService $audit)
    {
        if (! $request->user() || ! $request->user()->can('verification.pool.view')) {
            abort(403);
        }

        $this->assertVerificationDocumentAccessible($request, $document);

        if (! QualificationDocumentEvidence::isActiveEvidence($document)) {
            abort(404);
        }

        $audit->record(
            eventType: 'documents.previewed',
            module: 'Documents',
            actionName: 'previewed',
            message: 'Document previewed (admin verification).',
            entityType: QualificationDocument::class,
            entityId: $document->id,
            metadata: [
                'application_id' => $document->application_id,
                'document_type' => $document->document_type?->value ?? (string) $document->document_type,
                'version_number' => $document->version_number,
                'source' => 'admin_verification',
            ],
            actor: $request->user(),
        );

        return $documents->previewResponse($document);
    }

    public function download(Request $request, QualificationDocument $document, ApplicantDocumentService $documents, AuditLogService $audit)
    {
        if (! $request->user() || ! $request->user()->can('verification.pool.view')) {
            abort(403);
        }

        $this->assertVerificationDocumentAccessible($request, $document);

        if (! QualificationDocumentEvidence::isActiveEvidence($document)) {
            abort(404);
        }

        $audit->record(
            eventType: 'documents.downloaded',
            module: 'Documents',
            actionName: 'downloaded',
            message: 'Document downloaded (admin verification).',
            entityType: QualificationDocument::class,
            entityId: $document->id,
            metadata: [
                'application_id' => $document->application_id,
                'document_type' => $document->document_type?->value ?? (string) $document->document_type,
                'version_number' => $document->version_number,
                'source' => 'admin_verification',
            ],
            actor: $request->user(),
        );

        return $documents->downloadResponse($document);
    }

    private function assertVerificationDocumentAccessible(Request $request, QualificationDocument $document): void
    {
        $user = $request->user();
        if (! $user || ! VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
            return;
        }

        if ($document->qualification_id) {
            $document->loadMissing('qualification');
            if ($document->qualification) {
                VerificationQualificationAccess::ensureQualificationAccessible($user, $document->qualification);
            }

            return;
        }

        $document->loadMissing('application.qualifications');
        $application = $document->application;
        if (! $application) {
            abort(403);
        }

        VerificationQualificationAccess::ensureApplicationHasAssignableQualification($user, $application);

        $qualCount = $application->qualifications->count();
        if ($qualCount > 1) {
            abort(403);
        }
        if ($qualCount === 1) {
            $only = $application->qualifications->first();
            if ($only) {
                VerificationQualificationAccess::ensureQualificationAccessible($user, $only);
            }
        }
    }
}
