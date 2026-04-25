<?php

namespace App\Domain\Documents;

use App\Domain\Audit\AuditLogService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\DocumentType;
use App\Enums\DocumentVisibility;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Models\Application;
use App\Models\QualificationDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ApplicantDocumentService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
    )
    {
    }

    public function upload(Application $application, DocumentType $documentType, UploadedFile $file, User $actor): QualificationDocument
    {
        $disk = config('filesystems.default', 'local');

        return DB::transaction(function () use ($application, $documentType, $file, $actor, $disk) {
            $existing = QualificationDocument::query()
                ->where('application_id', $application->id)
                ->where('document_type', $documentType->value)
                ->lockForUpdate()
                ->get();

            $previousCurrent = $existing->firstWhere('is_current_version', true);
            $nextVersion = ((int) $existing->max('version_number')) + 1;

            QualificationDocument::query()
                ->where('application_id', $application->id)
                ->where('document_type', $documentType->value)
                ->where('is_current_version', true)
                ->update(['is_current_version' => false]);

            $sha256 = hash_file('sha256', $file->getRealPath());

            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $storedName = sprintf(
                '%s_v%s_%s.%s',
                $documentType->value,
                $nextVersion,
                Str::random(10),
                $extension,
            );

            $directory = sprintf('private/applications/%s/%s', $application->uuid, $documentType->value);
            $path = $file->storeAs($directory, $storedName, ['disk' => $disk]);

            $document = QualificationDocument::create([
                'application_id' => $application->id,
                'qualification_id' => $application->qualification?->id,
                'document_type' => $documentType,
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $storedName,
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $file->getClientMimeType() ?: $file->getMimeType() ?: 'application/octet-stream',
                'extension' => $extension,
                'size_bytes' => (int) $file->getSize(),
                'sha256_hash' => $sha256,
                'visibility' => DocumentVisibility::Private,
                'uploaded_by_user_id' => $actor->id,
                'version_number' => $nextVersion,
                'is_current_version' => true,
            ]);

            $eventType = $previousCurrent ? 'documents.replaced' : 'documents.uploaded';
            $actionName = $previousCurrent ? 'replaced' : 'uploaded';

            $this->audit->record(
                eventType: $eventType,
                module: 'Documents',
                actionName: $actionName,
                message: $previousCurrent ? 'Document replaced.' : 'Document uploaded.',
                entityType: QualificationDocument::class,
                entityId: $document->id,
                beforeState: $previousCurrent
                    ? [
                        'previous_document_id' => $previousCurrent->id,
                        'previous_version_number' => $previousCurrent->version_number,
                        'previous_sha256_hash' => $previousCurrent->sha256_hash,
                        'previous_path' => $previousCurrent->path,
                    ]
                    : null,
                afterState: [
                    'document_type' => $documentType->value,
                    'version_number' => $document->version_number,
                    'sha256_hash' => $document->sha256_hash,
                    'path' => $document->path,
                    'mime_type' => $document->mime_type,
                    'size_bytes' => $document->size_bytes,
                ],
                metadata: [
                    'application_id' => $application->id,
                    'qualification_id' => $application->qualification?->id,
                ],
                actor: $actor,
            );

            $this->lifecycle->event(
                application: $application,
                eventType: 'documents',
                eventCodeBase: $previousCurrent ? 'documents.replaced' : 'documents.uploaded',
                stage: LifecycleStage::Wizard,
                title: $previousCurrent ? 'Document replaced' : 'Document uploaded',
                description: 'Supporting document updated.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'document_type' => $documentType->value,
                    'document_id' => $document->id,
                    'version_number' => $document->version_number,
                ],
                occurredAt: now(),
            );

            $this->lifecycle->milestone(
                application: $application,
                eventType: 'wizard',
                eventCode: 'wizard.step3.documents_updated',
                stage: LifecycleStage::Wizard,
                title: 'Documents updated',
                description: 'Applicant updated supporting documents.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                occurredAt: now(),
            );

            return $document;
        });
    }

    public function downloadResponse(QualificationDocument $document)
    {
        /** @var \Illuminate\Contracts\Filesystem\Filesystem|\Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($document->disk);

        // Intelephense can struggle with dynamic disk methods; this keeps runtime behavior unchanged.
        return $disk->download($document->path, $document->original_name);
    }

    public function previewResponse(QualificationDocument $document)
    {
        $headers = [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($document->original_name).'"',
        ];

        /** @var \Illuminate\Contracts\Filesystem\Filesystem|\Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($document->disk);

        return $disk->response($document->path, $document->original_name, $headers);
    }

    public function delete(QualificationDocument $document, User $actor): void
    {
        DB::transaction(function () use ($document, $actor) {
            $document->refresh();

            $applicationId = $document->application_id;
            $application = Application::query()->whereKey($applicationId)->first();
            $type = $document->document_type?->value ?? (string) $document->document_type;
            $wasCurrent = (bool) $document->is_current_version;
            $before = [
                'document_id' => $document->id,
                'document_type' => $type,
                'version_number' => $document->version_number,
                'sha256_hash' => $document->sha256_hash,
                'path' => $document->path,
                'disk' => $document->disk,
                'is_current_version' => $wasCurrent,
            ];

            if ($wasCurrent) {
                $next = QualificationDocument::query()
                    ->where('application_id', $applicationId)
                    ->where('document_type', $type)
                    ->where('id', '!=', $document->id)
                    ->orderByDesc('version_number')
                    ->first();

                if ($next) {
                    $next->forceFill(['is_current_version' => true])->save();
                }
            }

            // Delete file first; ignore missing files gracefully.
            try {
                Storage::disk($document->disk)->delete($document->path);
            } catch (\Throwable) {
                // ignore storage errors in non-production; DB record will still be removed
                Log::warning('Document storage delete failed (ignored)', [
                    'document_id' => $before['document_id'],
                    'disk' => $before['disk'],
                    'path' => $before['path'],
                ]);
            }

            $document->delete();

            $this->audit->record(
                eventType: 'documents.deleted',
                module: 'Documents',
                actionName: 'deleted',
                message: 'Document deleted by applicant.',
                entityType: QualificationDocument::class,
                entityId: $before['document_id'],
                beforeState: $before,
                afterState: null,
                metadata: [
                    'application_id' => $applicationId,
                ],
                actor: $actor,
            );

            if ($application) {
                $this->lifecycle->event(
                    application: $application,
                    eventType: 'documents',
                    eventCodeBase: 'documents.deleted',
                    stage: LifecycleStage::Wizard,
                    title: 'Document deleted',
                    description: 'Supporting document removed.',
                    visibility: LifecycleVisibility::Both,
                    actor: $actor,
                    metadata: [
                        'document_id' => $document->id,
                        'document_type' => $type,
                        'was_current' => $wasCurrent,
                    ],
                    occurredAt: now(),
                );

                $this->lifecycle->milestone(
                    application: $application,
                    eventType: 'wizard',
                    eventCode: 'wizard.step3.documents_updated',
                    stage: LifecycleStage::Wizard,
                    title: 'Documents updated',
                    description: 'Applicant updated supporting documents.',
                    visibility: LifecycleVisibility::Both,
                    actor: $actor,
                    occurredAt: now(),
                );
            }
        });
    }
}
