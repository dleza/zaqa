<?php

namespace App\Http\Requests\Concerns;

use App\Rules\UniqueZambianPrimaryPhone;
use App\Support\Phone\ZambianPrimaryPhone;

trait NormalizesZambianPrimaryPhone
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('phone_primary')) {
            return;
        }

        $phone = $this->input('phone_primary');
        if (! is_string($phone)) {
            return;
        }

        $phone = trim($phone);
        if ($phone === '') {
            $this->merge(['phone_primary' => null]);

            return;
        }

        $normalized = ZambianPrimaryPhone::tryNormalize($phone);
        if ($normalized !== null) {
            $this->merge(['phone_primary' => $normalized]);
        }
    }

    /**
     * @return list<string|\Illuminate\Contracts\Validation\ValidationRule>
     */
    protected function zambianPrimaryPhoneFormatRules(?int $ignoreUserId = null): array
    {
        return [
            'string',
            'digits:12',
            'starts_with:260',
            new UniqueZambianPrimaryPhone($ignoreUserId),
        ];
    }
}
