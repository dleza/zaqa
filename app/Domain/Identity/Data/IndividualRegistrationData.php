<?php

namespace App\Domain\Identity\Data;

class IndividualRegistrationData
{
    public function __construct(
        public readonly string $firstName,
        public readonly ?string $middleName,
        public readonly string $surname,
        public readonly string $phonePrimary,
        public readonly ?string $phoneSecondary,
        public readonly string $email,
        public readonly string $password,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            firstName: (string) $input['first_name'],
            middleName: isset($input['middle_name']) && $input['middle_name'] !== '' ? (string) $input['middle_name'] : null,
            surname: (string) $input['surname'],
            phonePrimary: (string) $input['phone_primary'],
            phoneSecondary: isset($input['phone_secondary']) && $input['phone_secondary'] !== '' ? (string) $input['phone_secondary'] : null,
            email: (string) $input['email'],
            password: (string) $input['password'],
        );
    }
}

