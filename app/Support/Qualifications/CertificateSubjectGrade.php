<?php

namespace App\Support\Qualifications;

final class CertificateSubjectGrade
{
    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        /** @var list<string> $grades */
        $grades = config('certificate_subjects.allowed_grades', []);

        return $grades;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function optionsForFrontend(): array
    {
        return array_map(
            fn (string $grade) => ['value' => $grade, 'label' => $grade],
            self::allowed(),
        );
    }

    public static function isAllowed(mixed $grade): bool
    {
        $normalized = self::normalize($grade);

        return $normalized !== null;
    }

    /**
     * Trim, uppercase letters, and return a canonical allowed grade or null.
     */
    public static function normalize(mixed $grade): ?string
    {
        $value = trim((string) ($grade ?? ''));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[1-9]$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^[a-z]$/i', $value) === 1) {
            $upper = strtoupper($value);

            return in_array($upper, self::allowed(), true) ? $upper : null;
        }

        return null;
    }
}
