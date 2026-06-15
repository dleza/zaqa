<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Audit\AuditLogService;
use App\Domain\Settings\DocumentSignatureService;
use App\Enums\DocumentSignatureType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\StoreDocumentSignatureRequest;
use App\Models\DocumentSignatureSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDocumentSignaturesController extends Controller
{
    public function index(Request $request, DocumentSignatureService $signatures): Response
    {
        if (! $request->user()?->can('settings.document_signatures.view')) {
            abort(403);
        }

        return Inertia::render('Admin/Settings/DocumentSignatures/Index', [
            'signatures' => [
                'certificate' => $signatures->activePayload(DocumentSignatureType::Certificate),
                'receipt' => $signatures->activePayload(DocumentSignatureType::Receipt),
            ],
            'can' => [
                'manage' => (bool) $request->user()?->can('settings.document_signatures.manage'),
            ],
        ]);
    }

    public function store(StoreDocumentSignatureRequest $request, DocumentSignatureService $signatures, AuditLogService $audit): RedirectResponse
    {
        $validated = $request->validated();
        $type = DocumentSignatureType::from((string) $validated['type']);

        $setting = $signatures->storeUpload(
            $type,
            $request->file('file'),
            $request->user(),
            isset($validated['display_name']) ? (string) $validated['display_name'] : null,
        );

        $audit->record(
            eventType: 'settings.document_signature_uploaded',
            module: 'Settings',
            actionName: 'document_signature_uploaded',
            message: 'Document signature uploaded.',
            entityType: DocumentSignatureSetting::class,
            entityId: $setting->id,
            metadata: ['type' => $type->value],
            actor: $request->user(),
        );

        return back()->with('success', ucfirst($type->value).' signature uploaded.');
    }

    public function deactivate(Request $request, DocumentSignatureSetting $documentSignatureSetting, DocumentSignatureService $signatures, AuditLogService $audit): RedirectResponse
    {
        if (! $request->user()?->can('settings.document_signatures.manage')) {
            abort(403);
        }

        $signatures->deactivate($documentSignatureSetting);

        $audit->record(
            eventType: 'settings.document_signature_deactivated',
            module: 'Settings',
            actionName: 'document_signature_deactivated',
            message: 'Document signature deactivated.',
            entityType: DocumentSignatureSetting::class,
            entityId: $documentSignatureSetting->id,
            metadata: ['type' => $documentSignatureSetting->type?->value],
            actor: $request->user(),
        );

        return back()->with('success', 'Signature deactivated.');
    }

    public function destroy(Request $request, DocumentSignatureSetting $documentSignatureSetting, DocumentSignatureService $signatures, AuditLogService $audit): RedirectResponse
    {
        if (! $request->user()?->can('settings.document_signatures.manage')) {
            abort(403);
        }

        $type = $documentSignatureSetting->type?->value;
        $id = $documentSignatureSetting->id;

        $signatures->remove($documentSignatureSetting);

        $audit->record(
            eventType: 'settings.document_signature_removed',
            module: 'Settings',
            actionName: 'document_signature_removed',
            message: 'Document signature removed.',
            entityType: DocumentSignatureSetting::class,
            entityId: $id,
            metadata: ['type' => $type],
            actor: $request->user(),
        );

        return back()->with('success', 'Signature removed.');
    }

    public function preview(Request $request, DocumentSignatureSetting $documentSignatureSetting): StreamedResponse|SymfonyResponse
    {
        if (! $request->user()?->can('settings.document_signatures.view')) {
            abort(403);
        }

        $disk = Storage::disk($documentSignatureSetting->disk);
        if (! $disk->exists($documentSignatureSetting->file_path)) {
            abort(404);
        }

        return $disk->response($documentSignatureSetting->file_path, 'signature.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
