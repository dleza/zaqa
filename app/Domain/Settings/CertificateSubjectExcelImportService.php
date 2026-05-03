<?php

namespace App\Domain\Settings;

use App\Models\CertificateSubject;
use App\Models\User;
use App\Support\Imports\SpreadsheetLoader;
use Illuminate\Http\UploadedFile;

final class CertificateSubjectExcelImportService
{
    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'subject' => 'name',
        'subject_name' => 'name',
        'active' => 'is_active',
        'sort' => 'sort_order',
    ];

    public function import(UploadedFile $file, User $user): DataImportResult
    {
        $parsed = SpreadsheetLoader::readAssociative(
            $file,
            ['name', 'is_active', 'sort_order'],
            self::HEADER_ALIASES,
        );

        $result = new DataImportResult;
        if ($parsed['header_errors'] !== []) {
            $result->errors = $parsed['header_errors'];

            return $result;
        }

        $canCreate = $user->can('settings.certificate_subjects.create');
        $canEdit = $user->can('settings.certificate_subjects.edit');

        foreach ($parsed['rows'] as $row) {
            $line = (int) ($row['_line'] ?? 0);
            unset($row['_line']);

            $name = trim((string) ($row['name'] ?? ''));
            $activeRaw = $row['is_active'] ?? null;
            $sortRaw = $row['sort_order'] ?? null;

            if ($name === '') {
                $result->errors[] = "Row {$line}: name is required.";

                continue;
            }

            $isActive = $this->parseBool($activeRaw);
            $sortOrder = $this->parseInt($sortRaw, 0);

            $existing = CertificateSubject::query()->where('name', $name)->first();

            if ($existing) {
                if (! $canEdit) {
                    $result->errors[] = "Row {$line}: subject \"{$name}\" exists — skipped (no edit permission).";
                    $result->skipped++;

                    continue;
                }
                $existing->forceFill([
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                ])->save();
                $result->updated++;
            } else {
                if (! $canCreate) {
                    $result->errors[] = "Row {$line}: cannot create \"{$name}\" — skipped (no create permission).";
                    $result->skipped++;

                    continue;
                }
                CertificateSubject::query()->create([
                    'name' => $name,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                ]);
                $result->created++;
            }
        }

        return $result;
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $s = strtolower(trim((string) $value));
        if ($s === '') {
            return true;
        }
        if (in_array($s, ['1', 'true', 'yes', 'y', 'active'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no', 'n', 'inactive'], true)) {
            return false;
        }

        return (bool) $value;
    }

    private function parseInt(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
