<?php

namespace App\Domain\Settings;

use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\User;
use App\Support\Imports\SpreadsheetLoader;
use Illuminate\Http\UploadedFile;

final class AwardingInstitutionExcelImportService
{
    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'institution' => 'name',
        'institution_name' => 'name',
        'country_iso' => 'country_iso_code',
        'country_code' => 'country_iso_code',
        'iso' => 'country_iso_code',
        'active' => 'is_active',
        'sort' => 'sort_order',
    ];

    public function import(UploadedFile $file, User $user): DataImportResult
    {
        $parsed = SpreadsheetLoader::readAssociative(
            $file,
            ['name', 'country_iso_code', 'is_active', 'sort_order'],
            self::HEADER_ALIASES,
        );

        $result = new DataImportResult;
        if ($parsed['header_errors'] !== []) {
            $result->errors = $parsed['header_errors'];

            return $result;
        }

        $canCreate = $user->can('settings.awarding_institutions.create');
        $canEdit = $user->can('settings.awarding_institutions.edit');

        foreach ($parsed['rows'] as $row) {
            $line = (int) ($row['_line'] ?? 0);
            unset($row['_line']);

            $name = trim((string) ($row['name'] ?? ''));
            $countryIso = strtoupper(trim((string) ($row['country_iso_code'] ?? '')));

            if ($name === '' || strlen($countryIso) !== 3 || ! ctype_alpha($countryIso)) {
                $result->errors[] = "Row {$line}: invalid name or country_iso_code (use 3-letter ISO).";

                continue;
            }

            $country = Country::query()->where('iso_code', $countryIso)->first();
            if (! $country) {
                $result->errors[] = "Row {$line}: unknown country ISO {$countryIso}.";

                continue;
            }

            $isActive = $this->parseBool($row['is_active'] ?? null);
            $sortOrder = $this->parseInt($row['sort_order'] ?? null, 0);

            $existing = AwardingInstitution::query()
                ->where('country_id', $country->id)
                ->where('name', $name)
                ->first();

            if ($existing) {
                if (! $canEdit) {
                    $result->errors[] = "Row {$line}: institution exists — skipped (no edit permission).";
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
                    $result->errors[] = "Row {$line}: cannot create institution — skipped (no create permission).";
                    $result->skipped++;

                    continue;
                }
                AwardingInstitution::query()->create([
                    'country_id' => $country->id,
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
