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

            $email = $this->nullIfBlank($data['email'] ?? null);
            $phonePrimary = $this->nullIfBlank($data['phone_primary'] ?? null);
            $phoneSecondary = $this->nullIfBlank($data['phone_secondary'] ?? null);

            $user->email = $email;
            $user->phone_primary = $phonePrimary;
            $user->phone_secondary = $phoneSecondary;

            if ($user->applicant_type === ApplicantType::Institution) {
                $profile = $user->institutionProfile ?: new InstitutionProfile(['user_id' => $user->id]);

                $profile->institution_name = (string) $data['institution_name'];
                $profile->email = $email;
                $profile->phone_primary = $phonePrimary;
                $profile->phone_secondary = $phoneSecondary;
                $profile->tpin = $data['tpin'] ?? null;
                $profile->contact_person_name = (string) $data['contact_person_name'];

                $profile->save();

                $user->name = (string) $data['institution_name'];
            } else {
                $profile = $user->applicantProfile ?: new ApplicantProfile(['user_id' => $user->id]);

                $profile->first_name = (string) $data['first_name'];
                $profile->middle_name = $data['middle_name'] ?? null;
                $profile->surname = (string) $data['surname'];
                $profile->gender = (string) $data['gender'];
                $profile->identity_type = (string) $data['identity_type'];

                $identityNumber = $data['identity_number'] ?? null;
                if ((string) $data['identity_type'] === 'passport') {
                    $profile->passport_number = $identityNumber;
                    $profile->nrc_number = null;
                } else {
                    $profile->nrc_number = $identityNumber;
                    $profile->passport_number = null;
                }
                $profile->email = $email;
                $profile->phone_primary = $phonePrimary;
                $profile->phone_secondary = $phoneSecondary;

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

    private function nullIfBlank(mixed $value): ?string
    {
        $str = trim((string) ($value ?? ''));

        return $str === '' ? null : $str;
    }
}
