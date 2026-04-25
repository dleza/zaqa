<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Audit\AuditLogService;
use App\Domain\Documents\ApplicantDocumentService;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UploadApplicationDocumentRequest;
use App\Models\Application;
use App\Models\QualificationDocument;
use Illuminate\Http\Request;

class ApplicantDocumentController extends Controller
{
    public function store(UploadApplicationDocumentRequest $request, Application $application, ApplicantDocumentService $documents)
    {
        $this->authorize('update', $application);

        $documentType = DocumentType::from((string) $request->validated()['document_type']);
        $file = $request->file('file');

        $documents->upload($application, $documentType, $file, $request->user());

        return back()->with('success', 'Document uploaded successfully.');
    }

    public function preview(Request $request, QualificationDocument $document, ApplicantDocumentService $documents, AuditLogService $audit)
    {
        $this->authorize('view', $document);

        $audit->record(
            eventType: 'documents.previewed',
            module: 'Documents',
            actionName: 'previewed',
            message: 'Document previewed.',
            entityType: QualificationDocument::class,
            entityId: $document->id,
            metadata: [
                'application_id' => $document->application_id,
                'document_type' => $document->document_type?->value ?? (string) $document->document_type,
                'version_number' => $document->version_number,
            ],
            actor: $request->user(),
        );

        return $documents->previewResponse($document);
    }

    public function download(Request $request, QualificationDocument $document, ApplicantDocumentService $documents, AuditLogService $audit)
    {
        $this->authorize('download', $document);

        $audit->record(
            eventType: 'documents.downloaded',
            module: 'Documents',
            actionName: 'downloaded',
            message: 'Document downloaded.',
            entityType: QualificationDocument::class,
            entityId: $document->id,
            metadata: [
                'application_id' => $document->application_id,
                'document_type' => $document->document_type?->value ?? (string) $document->document_type,
                'version_number' => $document->version_number,
            ],
            actor: $request->user(),
        );

        return $documents->downloadResponse($document);
    }

    public function destroy(Request $request, QualificationDocument $document, ApplicantDocumentService $documents)
    {
        $this->authorize('delete', $document);

        $documents->delete($document, $request->user());

        return back()->with('success', 'Document deleted.');
    }
}

