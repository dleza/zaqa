<?php

namespace App\Support\Imports;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class SpreadsheetLoader
{
    /**
     * Read first sheet: row 1 = headers (mapped to snake_case keys). Following rows = data.
     *
     * @param  array<string, string>  $headerAliases  normalized header label -> canonical column key
     * @return array{rows: list<array<string, mixed>>, header_errors: list<string>}
     */
    public static function readAssociative(UploadedFile $file, array $requiredCanonicalHeaders, array $headerAliases = []): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $grid = $sheet->toArray(null, true, true, false);
        if ($grid === [] || ! isset($grid[0])) {
            return ['rows' => [], 'header_errors' => ['The spreadsheet is empty.']];
        }

        $headerRow = array_shift($grid);
        $canonicalByCol = [];
        foreach ($headerRow as $idx => $cell) {
            $label = self::normalizeHeaderLabel((string) $cell);
            if ($label === '') {
                continue;
            }
            $canonical = $headerAliases[$label] ?? $label;
            $canonicalByCol[$idx] = $canonical;
        }

        $missing = [];
        foreach ($requiredCanonicalHeaders as $req) {
            if (! in_array($req, $canonicalByCol, true)) {
                $missing[] = $req;
            }
        }
        if ($missing !== []) {
            return [
                'rows' => [],
                'header_errors' => [
                    'Missing required column(s): '.implode(', ', $missing).'. Use the downloaded template.',
                ],
            ];
        }

        $rows = [];
        foreach ($grid as $lineNum => $line) {
            $assoc = [];
            $any = false;
            foreach ($line as $idx => $value) {
                if (! isset($canonicalByCol[$idx])) {
                    continue;
                }
                $key = $canonicalByCol[$idx];
                if (is_string($value)) {
                    $value = trim($value);
                }
                if ($value !== null && $value !== '') {
                    $any = true;
                }
                $assoc[$key] = $value;
            }
            if (! $any) {
                continue;
            }
            $assoc['_line'] = $lineNum + 2;
            $rows[] = $assoc;
        }

        return ['rows' => $rows, 'header_errors' => []];
    }

    private static function normalizeHeaderLabel(string $cell): string
    {
        $cell = preg_replace('/^\xEF\xBB\xBF/', '', $cell) ?? $cell;
        $cell = strtolower(trim($cell));
        $cell = str_replace([' ', '-'], '_', $cell);

        return $cell;
    }
}
