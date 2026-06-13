<?php

namespace App\Domain\Notifications\Sms;

use App\Support\Phone\ZambiaMsisdnNormalizer;

final class SmsPhoneNormalizer
{
    /**
     * Normalize for Zamtel contacts segment (bracketed international MSISDN).
     */
    public function normalizeForZamtel(string $input): string
    {
        $international = ZambiaMsisdnNormalizer::normalizeForCGrate($input, 'international_without_plus');

        return '['.$international.']';
    }

    /**
     * Normalize for display/logging (international without plus).
     */
    public function normalizeForStorage(string $input): string
    {
        return ZambiaMsisdnNormalizer::normalizeForCGrate($input, 'international_without_plus');
    }

    public function isValid(string $input): bool
    {
        try {
            $this->normalizeForStorage($input);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
