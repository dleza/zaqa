<?php

namespace App\Support\Phone;

final class ZambianPrimaryPhone
{
    public static function normalize(string $input): string
    {
        return ZambiaMsisdnNormalizer::normalizeForCGrate($input, 'international_without_plus');
    }

    public static function tryNormalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        try {
            return self::normalize($input);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public static function isValidNormalized(string $value): bool
    {
        return preg_match('/^260\d{9}$/', $value) === 1;
    }

    /**
     * @return list<string>
     */
    public static function equivalentStorageValues(string $normalized): array
    {
        if (! self::isValidNormalized($normalized)) {
            return [$normalized];
        }

        $nsn = substr($normalized, 3);

        return array_values(array_unique([
            $normalized,
            '+'.$normalized,
            '0'.$nsn,
            $nsn,
        ]));
    }

    public static function canonicalOrStored(?string $stored): ?string
    {
        $stored = trim((string) ($stored ?? ''));

        if ($stored === '') {
            return null;
        }

        return self::tryNormalize($stored) ?? $stored;
    }
}
