<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Audit\AuditLogService;
use App\Enums\ApplicantType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UploadApplicantIdentityDocumentRequest;
use App\Models\ApplicantProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ApplicantProfileIdentityDocumentController extends Controller
{
    public function store(UploadApplicantIdentityDocumentRequest $request, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->applicant_type !== ApplicantType::Individual) {
            abort(403);
        }

        $file = $request->file('file');
        if (! $file) {
            return back()->withErrors(['file' => 'Please choose a file to upload.']);
        }

        $disk = config('filesystems.default', 'local');

        return DB::transaction(function () use ($user, $file, $disk, $audit) {
            $profile = $user->applicantProfile ?: new ApplicantProfile(['user_id' => $user->id]);
            $profile->user_id = $user->id;

            if ($profile->identity_document_path && $profile->identity_document_disk) {
                Storage::disk($profile->identity_document_disk)->delete($profile->identity_document_path);
            }

            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $storedName = sprintf('identity_%s_%s.%s', now()->format('YmdHis'), Str::random(8), $extension);
            $directory = sprintf('private/applicant-profiles/%s/identity', $user->id);
            $path = $file->storeAs($directory, $storedName, ['disk' => $disk]);

            $profile->identity_document_disk = $disk;
            $profile->identity_document_path = $path;
            $profile->identity_document_original_name = $file->getClientOriginalName();
            $profile->identity_document_size_bytes = (int) $file->getSize();
            $profile->identity_document_uploaded_at = now();
            $profile->save();

            $audit->record(
                eventType: 'applicants.profile_identity_document_uploaded',
                module: 'Applicants',
                actionName: 'profile_identity_document_uploaded',
                message: 'Applicant uploaded a profile-level identity document.',
                entityType: ApplicantProfile::class,
                entityId: $profile->id,
                metadata: [
                    'user_id' => $user->id,
                ],
                actor: $user,
            );

            return redirect()->route('applicant.profile.edit')->with('success', 'Identity document saved.');
        });
    }

    public function destroy(AuditLogService $audit): RedirectResponse
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
