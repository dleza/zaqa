<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogService;
use App\Domain\Identity\Data\IndividualRegistrationData;
use App\Domain\Identity\Data\InstitutionRegistrationData;
use App\Enums\ApplicantType;
use App\Models\ApplicantProfile;
use App\Models\InstitutionProfile;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class ApplicantRegistrationService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly AccountActivationService $activation,
    ) {
    }

    public function registerIndividual(IndividualRegistrationData $data): User
    {
        return DB::transaction(function () use ($data) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $fullName = trim($data->firstName.' '.$data->surname);

            $user = User::create([
                'uuid' => (string) Str::uuid(),
                'name' => $fullName,
                'email' => $data->email,
                'phone_primary' => $data->phonePrimary,
                'phone_secondary' => $data->phoneSecondary,
                'login_identifier_type' => $data->loginIdentifierType,
                'password' => $data->password,
                'applicant_type' => ApplicantType::Individual,
                'is_active' => false,
            ]);

            $user->assignRole('Applicant');

            ApplicantProfile::create([
                'user_id' => $user->id,
                'first_name' => $data->firstName,
                'middle_name' => $data->middleName,
                'surname' => $data->surname,
                'nrc_number' => null,
                'passport_number' => null,
                'email' => $data->email,
                'phone_primary' => $data->phonePrimary,
                'phone_secondary' => $data->phoneSecondary,
            ]);

            event(new Registered($user));

            $this->audit->record(
                eventType: 'identity.applicant_registered',
                module: 'Identity',
                actionName: 'applicant_registered',
                message: 'Applicant registered (individual).',
                entityType: $user::class,
                entityId: $user->id,
                metadata: [
                    'applicant_type' => $user->applicant_type?->value,
                ],
                actor: $user,
            );

            $this->activation->issueActivationChallenges($user);

            return $user;
        });
    }

    public function registerInstitution(InstitutionRegistrationData $data): User
    {
        return DB::transaction(function () use ($data) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $user = User::create([
                'uuid' => (string) Str::uuid(),
                'name' => $data->institutionName,
                'email' => $data->email,
                'phone_primary' => $data->phonePrimary,
                'phone_secondary' => $data->phoneSecondary,
                'login_identifier_type' => $data->loginIdentifierType,
                'password' => $data->password,
                'applicant_type' => ApplicantType::Institution,
                'is_active' => false,
            ]);

            $user->assignRole('Applicant');

            InstitutionProfile::create([
                'user_id' => $user->id,
                'institution_name' => $data->institutionName,
                'email' => $data->email,
                'phone_primary' => $data->phonePrimary,
                'phone_secondary' => $data->phoneSecondary,
                'tpin' => $data->tpin,
                'contact_person_name' => $data->contactPersonName,
            ]);

            event(new Registered($user));

            $this->audit->record(
                eventType: 'identity.applicant_registered',
                module: 'Identity',
                actionName: 'applicant_registered',
                message: 'Applicant registered (institution).',
                entityType: $user::class,
                entityId: $user->id,
                metadata: [
                    'applicant_type' => $user->applicant_type?->value,
                ],
                actor: $user,
            );

            $this->activation->issueActivationChallenges($user);

            return $user;
        });
    }
}

