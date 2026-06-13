<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Phone\ZambianPrimaryPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class UniqueZambianPrimaryPhone implements ValidationRule
{
    public function __construct(
        private readonly ?int $ignoreUserId = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if (! ZambianPrimaryPhone::isValidNormalized($value)) {
            return;
        }

        $query = User::query()
            ->whereIn('phone_primary', ZambianPrimaryPhone::equivalentStorageValues($value));

        if ($this->ignoreUserId !== null) {
            $query->where('id', '!=', $this->ignoreUserId);
        }

        if ($query->exists()) {
            $fail('This phone number has already been registered.');
        }
    }
}
