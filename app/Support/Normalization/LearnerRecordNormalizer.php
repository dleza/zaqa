<?php

namespace App\Support\Normalization;

use Illuminate\Support\Str;

final class LearnerRecordNormalizer
{
    public static function normalizeFullName(?string $value): ?string
    {
        $value = self::trimToNull($value);
        if ($value === null) {
            return null;
        }

        $ascii = Str::ascii($value);
        $ascii = strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9 ]+/', ' ', $ascii) ?? $ascii;
        $ascii = preg_replace('/\s+/', ' ', trim($ascii)) ?? trim($ascii);

        $tokens = array_values(array_filter(explode(' ', $ascii), fn ($t) => $t !== ''));
        sort($tokens);

        $norm = trim(implode(' ', $tokens));

        return $norm !== '' ? $norm : null;
    }

    public static function normalizeStudentId(?string $value): ?string
    {
        $value = self::trimToNull($value);
        if ($value === null) {
            return null;
        }

        return self::normalizeAlphaNum($value);
    }

    public static function normalizeCertificateNo(?string $value): ?string
    {
        $value = self::trimToNull($value);
        if ($value === null) {
            return null;
        }

        return self::normalizeAlphaNum($value);
    }

    public static function normalizeNrc(?string $value): ?string
    {
        $value = self::trimToNull($value);
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

        return $digits !== '' ? $digits : null;
    }

    public static function normalizePassport(?string $value): ?string
    {
        $value = self::trimToNull($value);
        if ($value === null) {
            return null;
        }

        return self::normalizeAlphaNum($value);
    }

    public static function normalizeProgramTitle(?string $value): ?string
    {
        $value = self::trimToNull($value);
        if ($value === null) {
            return null;
        }

        $ascii = Str::ascii($value);
        $ascii = strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9 ]+/', ' ', $ascii) ?? $ascii;
        $ascii = preg_replace('/\s+/', ' ', trim($ascii)) ?? trim($ascii);

        return $ascii !== '' ? $ascii : null;
    }

    public static function normalizeNameParts(?string $firstName, ?string $otherNames, ?string $lastName): ?string
    {
        $full = trim((string) ($firstName ?? '')).' '.trim((string) ($otherNames ?? '')).' '.trim((string) ($lastName ?? ''));
        $full = trim($full);
        if ($full === '') {
            return null;
        }

        $ascii = Str::ascii($full);
        $ascii = strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9 ]+/', ' ', $ascii) ?? $ascii;
        $ascii = preg_replace('/\s+/', ' ', trim($ascii)) ?? trim($ascii);

        $tokens = array_values(array_filter(explode(' ', $ascii), fn ($t) => $t !== ''));
        sort($tokens);

        $norm = trim(implode(' ', $tokens));

        return $norm !== '' ? $norm : null;
    }

    public static function dedupeHash(?int $awardingInstitutionId, ?string $certificateNoNormalized, ?string $studentIdNormalized, ?int $yearAwarded): ?string
    {
        $inst = $awardingInstitutionId && $awardingInstitutionId > 0 ? $awardingInstitutionId : null;
        $cert = self::trimToNull($certificateNoNormalized);
        $student = self::trimToNull($studentIdNormalized);
        $year = $yearAwarded && $yearAwarded > 0 ? $yearAwarded : null;

        if ($inst && $cert) {
            return hash('sha256', "inst:{$inst}|cert:{$cert}");
        }
        if ($inst && $student && $year) {
            return hash('sha256', "inst:{$inst}|student:{$student}|year:{$year}");
        }
        if ($cert) {
            return hash('sha256', "cert:{$cert}");
        }
        if ($student && $year) {
            return hash('sha256', "student:{$student}|year:{$year}");
        }

        return null;
    }

    private static function normalizeAlphaNum(string $value): ?string
    {
        $value = strtoupper($value);
        $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';

        return $value !== '' ? $value : null;
    }

    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $t = trim($value);

        return $t === '' ? null : $t;
    }
}
