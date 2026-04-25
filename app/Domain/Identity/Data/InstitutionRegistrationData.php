<?php

namespace App\Domain\Identity\Data;

class InstitutionRegistrationData
{
    public function __construct(
        public readonly string $institutionName,
        public readonly string $phonePrimary,
        public readonly ?string $phoneSecondary,
        public readonly string $email,
        public readonly string $tpin,
        public readonly ?string $contactPersonName,
        public readonly string $password,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            institutionName: (string) $input['institution_name'],
            phonePrimary: (string) $input['phone_primary'],
            phoneSecondary: isset($input['phone_secondary']) && $input['phone_secondary'] !== '' ? (string) $input['phone_secondary'] : null,
            email: (string) $input['email'],
            tpin: (string) $input['tpin'],
            contactPersonName: isset($input['contact_person_name']) && $input['contact_person_name'] !== '' ? (string) $input['contact_person_name'] : null,
            password: (string) $input['password'],
        );
    }
}

