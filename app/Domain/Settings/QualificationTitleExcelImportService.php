<?php

namespace App\Domain\Settings;

use App\Models\QualificationTitle;
use App\Models\QualificationType;
use App\Models\User;
use App\Support\Imports\SpreadsheetLoader;
use Illuminate\Http\UploadedFile;

final class QualificationTitleExcelImportService
{
    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'name' => 'title',
        'qualification_type_name' => 'qualification_type',
        'type' => 'qualification_type',
        'active' => 'is_active',
        'sort' => 'sort_order',
        'notes' => 'description',
    ];

    public function import(UploadedFile $file, User $user): DataImportResult
    {
        $parsed = SpreadsheetLoader::readAssociative(
            $file,
            ['title', 'qualification_type', 'is_active', 'sort_order', 'description'],
            self::HEADER_ALIASES,
        );

        $result = new DataImportResult;
        if ($parsed['header_errors'] !== []) {
            $result->errors = $parsed['header_errors'];

            return $result;
        }

        $canCreate = $user->can('settings.qualification_titles.create');
        $canEdit = $user->can('settings.qualification_titles.edit');

        foreach ($parsed['rows'] as $row) {
            $line = (int) ($row['_line'] ?? 0);
            unset($row['_line']);

            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                $result->errors[] = "Row {$line}: title is required.";

                continue;
            }

            $normalized = QualificationTitle::normalizeName($title);
            if ($normalized === '') {
                $result->errors[] = "Row {$line}: title could not be normalized.";

                continue;
            }

            $typeRaw = trim((string) ($row['qualification_type'] ?? ''));
            $qualificationTypeId = null;
            if ($typeRaw !== '') {
                $qualificationTypeId = $this->resolveQualificationTypeId($typeRaw);
                if ($qualificationTypeId === null) {
                    $result->errors[] = "Row {$line}: qualification type \"{$typeRaw}\" not found.";

                    continue;
                }
            }

            $isActive = $this->parseBool($row['is_active'] ?? null);
            $sortOrder = $this->parseInt($row['sort_order'] ?? null, 0);
            $description = trim((string) ($row['description'] ?? ''));
            $description = $description !== '' ? $description : null;

            $existing = QualificationTitle::query()->where('name_normalized', $normalized)->first();

            if ($existing) {
                if (! $canEdit) {
                    $result->errors[] = "Row {$line}: title \"{$title}\" exists — skipped (no edit permission).";
                    $result->skipped++;

                    continue;
                }
                $existing->forceFill([
                    'name' => $title,
                    'qualification_type_id' => $qualificationTypeId,
                    'description' => $description,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                ])->save();
                $result->updated++;
            } else {
                if (! $canCreate) {
                    $result->errors[] = "Row {$line}: cannot create \"{$title}\" — skipped (no create permission).";
                    $result->skipped++;

                    continue;
                }
                QualificationTitle::query()->create([
                    'name' => $title,
                    'qualification_type_id' => $qualificationTypeId,
                    'description' => $description,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                ]);
                $result->created++;
            }
        }

        return $result;
    }

    private function resolveQualificationTypeId(string $raw): ?int
    {
        $type = QualificationType::query()
            ->where('name', $raw)
            ->orWhere('zqf_level_code', $raw)
            ->orWhere('short_name', $raw)
            ->first();

        return $type?->id;
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
