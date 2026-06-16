<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Verification\AdminQualificationDocumentService;
use App\Domain\Verification\VerificationQualificationAccess;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Verification\AdminUploadVerificationQualificationDocumentRequest;
use App\Models\ApplicantProfile;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AdminVerificationQualificationDocumentController extends Controller
{
    public function store(
        AdminUploadVerificationQualificationDocumentRequest $request,
        Qualification $qualification,
        AdminQualificationDocumentService $documents,
    ): RedirectResponse {
        $user = $request->user();
        if ($user && VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
            $this->assertLevel1EditState($qualification);
        }

        $documentType = DocumentType::from((string) $request->validated('document_type'));
        $documents->upload(
            $qualification,
            $documentType,
            $request->file('file'),
            $request->user(),
            $request->validated('correction_note'),
        );

        return redirect()
            ->route('admin.verification.qualifications.edit', $qualification)
            ->with('success', 'Document uploaded.');
    }

    public function destroy(
        Request $request,
        Qualification $qualification,
        QualificationDocument $document,
        AdminQualificationDocumentService $documents,
    ): RedirectResponse {
        abort_unless(
            $request->user()?->can('verification.level1.process') || $request->user()?->can('verification.level2.review'),
            403
        );
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);

        $user = $request->user();
        if ($user && VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
            $this->assertLevel1EditState($qualification);
        }

        $request->validate([
            'correction_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $documents->delete($qualification, $document, $request->user(), $request->input('correction_note'));

        return redirect()
            ->route('admin.verification.qualifications.edit', $qualification)
            ->with('success', 'Document removed.');
    }

    public function previewProfileIdentity(
        Request $request,
        Qualification $qualification,
        AuditLogService $audit,
    ): SymfonyResponse {
        $this->authorizeIdentityAccess($request, $qualification);

        $profile = $this->resolveProfileIdentity($qualification);
        abort_unless($profile instanceof ApplicantProfile && $profile->identity_document_path, 404);

        $audit->record(
            eventType: 'documents.previewed',
            module: 'Documents',
            actionName: 'previewed',
            message: 'Applicant profile identity document previewed (admin verification edit).',
            entityType: ApplicantProfile::class,
            entityId: $profile->id,
            metadata: [
                'application_id' => $qualification->application_id,
                'qualification_id' => $qualification->id,
                'source' => 'admin_verification_edit',
            ],
            actor: $request->user(),
        );

        $disk = Storage::disk((string) $profile->identity_document_disk);
        $name = (string) ($profile->identity_document_original_name ?: 'identity-document');

        return $disk->response($profile->identity_document_path, $name, [
            'Content-Type' => $this->guessMimeFromName($name),
            'Content-Disposition' => 'inline; filename="'.addslashes($name).'"',
        ]);
    }

    public function downloadProfileIdentity(
        Request $request,
        Qualification $qualification,
        AuditLogService $audit,
    ): SymfonyResponse {
        $this->authorizeIdentityAccess($request, $qualification);

        $profile = $this->resolveProfileIdentity($qualification);
        abort_unless($profile instanceof ApplicantProfile && $profile->identity_document_path, 404);

        $audit->record(
            eventType: 'documents.downloaded',
            module: 'Documents',
            actionName: 'downloaded',
            message: 'Applicant profile identity document downloaded (admin verification edit).',
            entityType: ApplicantProfile::class,
            entityId: $profile->id,
            metadata: [
                'application_id' => $qualification->application_id,
                'qualification_id' => $qualification->id,
                'source' => 'admin_verification_edit',
            ],
            actor: $request->user(),
        );

        $disk = Storage::disk((string) $profile->identity_document_disk);
        $name = (string) ($profile->identity_document_original_name ?: 'identity-document');

        return $disk->download($profile->identity_document_path, $name);
    }

    private function authorizeIdentityAccess(Request $request, Qualification $qualification): void
    {
        abort_unless(
            $request->user()?->can('verification.level1.process') || $request->user()?->can('verification.level2.review'),
            403
        );
        VerificationQualificationAccess::ensureQualificationAccessible($request->user(), $qualification);
    }

    private function resolveProfileIdentity(Qualification $qualification): ?ApplicantProfile
    {
        $qualification->loadMissing('application.applicant.applicantProfile');

        return $qualification->application?->applicant?->applicantProfile;
    }

    private function assertLevel1EditState(Qualification $qualification): void
    {
        $allowed = [\App\Enums\VerificationState::AssignedToLevel1, \App\Enums\VerificationState::UnderLevel1Review];
        $vs = $qualification->verification_state;
        abort_unless($vs instanceof \App\Enums\VerificationState && in_array($vs, $allowed, true), 403);
    }

    private function guessMimeFromName(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return 'application/pdf';
        }
        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            return 'image/jpeg';
        }
        if ($ext === 'png') {
            return 'image/png';
        }
        if ($ext === 'webp') {
            return 'image/webp';
        }

        return 'application/octet-stream';
    }
}
