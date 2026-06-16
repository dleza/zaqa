<?php

namespace App\Domain\Verification;

/**
 * Builds human-readable field-level diffs for admin verification qualification corrections.
 */
final class VerificationQualificationCorrectionDiff
{
    /**
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'qualification_holder_name' => 'Holder name',
        'nrc_passport_number' => 'NRC / passport',
        'country_id' => 'Country of award',
        'country_name_other' => 'Country (other)',
        'awarding_institution_id' => 'Awarding institution',
        'awarding_institution_name_other' => 'Institution name (other)',
        'awarding_institution_name' => 'Awarding institution name',
        'certificate_number' => 'Certificate number',
        'student_number' => 'Student number',
        'examination_number' => 'Examination number',
        'title_of_qualification' => 'Qualification title',
        'award_date' => 'Award date',
        'qualification_type_id' => 'Qualification type',
        'transcript_reason' => 'Transcript / programme notes',
        'notes' => 'Internal notes',
    ];

    /**
     * @param  array<string, mixed>  $beforeQualification
     * @param  array<string, mixed>  $afterQualification
     * @param  list<array{subject_name: string|null, grade: string|null, display_order: int|null}>  $beforeSubjects
     * @param  list<array{subject_name: string|null, grade: string|null, display_order: int|null}>  $afterSubjects
     * @return list<array{field: string, label: string, from: mixed, to: mixed}>
     */
    public static function build(
        array $beforeQualification,
        array $afterQualification,
        array $beforeSubjects,
        array $afterSubjects,
    ): array {
        $changes = [];

        foreach (self::FIELD_LABELS as $field => $label) {
            $from = $beforeQualification[$field] ?? null;
            $to = $afterQualification[$field] ?? null;
            if (self::valuesEqual($from, $to)) {
                continue;
            }
            $changes[] = [
                'field' => $field,
                'label' => $label,
                'from' => self::displayValue($from),
                'to' => self::displayValue($to),
            ];
        }

        if (! self::valuesEqual($beforeSubjects, $afterSubjects)) {
            $changes[] = [
                'field' => 'subject_results',
                'label' => 'Subject results',
                'from' => self::formatSubjects($beforeSubjects),
                'to' => self::formatSubjects($afterSubjects),
            ];
        }

        return $changes;
    }

    /**
     * @param  list<array{subject_name: string|null, grade: string|null, display_order: int|null}>  $rows
     */
    private static function formatSubjects(array $rows): string
    {
        if ($rows === []) {
            return '—';
        }

        return collect($rows)
            ->map(fn (array $row) => trim((string) ($row['subject_name'] ?? 'Subject')).': '.trim((string) ($row['grade'] ?? '—')))
            ->implode('; ');
    }

    private static function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '—';
        }

        return (string) $value;
    }

    private static function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }

        return (string) $a === (string) $b;
    }
}
