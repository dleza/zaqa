<?php

namespace App\Domain\Applicants;

use App\Domain\Audit\AuditLogService;
use App\Enums\ApplicantType;
use App\Models\ApplicantProfile;
use App\Models\InstitutionProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ApplicantDetailsService
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(User $user, array $data, User $actor): void
    {
        DB::transaction(function () use ($user, $data, $actor) {
            $user->loadMissing(['applicantProfile', 'institutionProfile']);

            $before = [
                'user' => $user->only(['name', 'email', 'phone_primary', 'phone_secondary', 'applicant_type']),
                'applicant_profile' => $user->applicantProfile?->toArray(),
                'institution_profile' => $user->institutionProfile?->toArray(),
            ];

            $user->email = (string) $data['email'];
            $user->phone_primary = (string) $data['phone_primary'];
            $user->phone_secondary = $data['phone_secondary'] ?? null;

            if ($user->applicant_type === ApplicantType::Institution) {
                $profile = $user->institutionProfile ?: new InstitutionProfile(['user_id' => $user->id]);

                $profile->institution_name = (string) $data['institution_name'];
                $profile->email = (string) $data['email'];
                $profile->phone_primary = (string) $data['phone_primary'];
                $profile->phone_secondary = $data['phone_secondary'] ?? null;
                $profile->tpin = $data['tpin'] ?? null;
                $profile->contact_person_name = (string) $data['contact_person_name'];

                $profile->save();

                $user->name = (string) $data['institution_name'];
            } else {
                $profile = $user->applicantProfile ?: new ApplicantProfile(['user_id' => $user->id]);

                $profile->first_name = (string) $data['first_name'];
                $profile->middle_name = $data['middle_name'] ?? null;
                $profile->surname = (string) $data['surname'];
                $profile->nrc_number = $data['nrc_number'] ?? null;
                $profile->passport_number = $data['passport_number'] ?? null;
                $profile->email = (string) $data['email'];
                $profile->phone_primary = (string) $data['phone_primary'];
                $profile->phone_secondary = $data['phone_secondary'] ?? null;

                $profile->save();

                $user->name = trim(($profile->first_name ?? '').' '.($profile->surname ?? ''));
            }

            $user->save();

            $user->refresh();
            $user->loadMissing(['applicantProfile', 'institutionProfile']);

            $after = [
                'user' => $user->only(['name', 'email', 'phone_primary', 'phone_secondary', 'applicant_type']),
                'applicant_profile' => $user->applicantProfile?->toArray(),
                'institution_profile' => $user->institutionProfile?->toArray(),
            ];

            $this->audit->record(
                eventType: 'applicants.details_saved',
                module: 'Applicants',
                actionName: 'details_saved',
                message: 'Applicant details updated.',
                entityType: User::class,
                entityId: $user->id,
                beforeState: $before,
                afterState: $after,
                actor: $actor,
            );
        });
    }
}

