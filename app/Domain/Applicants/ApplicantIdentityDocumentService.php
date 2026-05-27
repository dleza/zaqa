<?php

namespace App\Domain\Applicants;

use App\Domain\Audit\AuditLogService;
use App\Enums\ApplicantType;
use App\Models\ApplicantProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ApplicantIdentityDocumentService
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function saveProfileIdentityDocument(User $user, UploadedFile $file, User $actor): ApplicantProfile
    {
        if ($user->applicant_type !== ApplicantType::Individual) {
            throw new RuntimeException('Only individual applicants can have a profile identity document.');
        }

        $disk = config('filesystems.default', 'local');

        return DB::transaction(function () use ($user, $file, $disk, $actor) {
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

            $this->audit->record(
                eventType: 'applicants.profile_identity_document_uploaded',
                module: 'Applicants',
                actionName: 'profile_identity_document_uploaded',
                message: 'Applicant uploaded a profile-level identity document.',
                entityType: ApplicantProfile::class,
                entityId: $profile->id,
                metadata: [
                    'user_id' => $user->id,
                ],
                actor: $actor,
            );

            return $profile;
        });
    }
}

