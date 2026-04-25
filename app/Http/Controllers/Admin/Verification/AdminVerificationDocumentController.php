<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Documents\ApplicantDocumentService;
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
}

