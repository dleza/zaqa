<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Applicants\ApplicantIdentityDocumentService;
use App\Enums\ApplicantType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UploadApplicantIdentityDocumentRequest;
use App\Models\ApplicantProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

class ApplicantProfileIdentityDocumentController extends Controller
{
    public function store(UploadApplicantIdentityDocumentRequest $request, ApplicantIdentityDocumentService $identityDocuments): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->applicant_type !== ApplicantType::Individual) {
            abort(403);
        }

        $file = $request->file('file');
        if (! $file) {
            return back()->withErrors(['file' => 'Please choose a file to upload.']);
        }
        $identityDocuments->saveProfileIdentityDocument($user, $file, $user);

        return redirect()->route('applicant.profile.edit')->with('success', 'Identity document saved.');
    }

    public function destroy(\App\Domain\Audit\AuditLogService $audit): RedirectResponse
    {
        $user = request()->user();
        if (! $user instanceof User || $user->applicant_type !== ApplicantType::Individual) {
            abort(403);
        }

        $profile = $user->applicantProfile;
        if (! $profile || ! $profile->identity_document_path) {
            return redirect()->route('applicant.profile.edit');
        }

        if ($profile->identity_document_disk) {
            Storage::disk($profile->identity_document_disk)->delete($profile->identity_document_path);
        }

        $profile->forceFill([
            'identity_document_disk' => null,
            'identity_document_path' => null,
            'identity_document_original_name' => null,
            'identity_document_size_bytes' => null,
            'identity_document_uploaded_at' => null,
        ])->save();

        $audit->record(
            eventType: 'applicants.profile_identity_document_removed',
            module: 'Applicants',
            actionName: 'profile_identity_document_removed',
            message: 'Applicant removed their profile-level identity document.',
            entityType: ApplicantProfile::class,
            entityId: $profile->id,
            metadata: [
                'user_id' => $user->id,
            ],
            actor: $user,
        );

        return redirect()->route('applicant.profile.edit')->with('success', 'Identity document removed.');
    }
}
