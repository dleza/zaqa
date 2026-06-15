<?php

namespace App\Domain\LearnerRecords;

use App\Enums\LearnerRecordImportStatus;
use App\Enums\LearnerRecordSourceType;
use App\Models\LearnerRecord;
use App\Models\LearnerRecordImport;
use App\Support\Imports\ChunkReadFilter;
use App\Support\Normalization\LearnerRecordNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LearnerRecordExcelImportProcessor
{
    private const CHUNK_SIZE = 500;
    private const MAX_ERROR_ROWS_STORED = 200;

    /**
     * Process a learner record import asynchronously (queued).
     *
     * @throws \Throwable
     */
    public function process(LearnerRecordImport $import): void
    {
        $disk = config('filesystems.default', 'local');
        $path = (string) ($import->file_path ?? '');
        if ($path === '') {
            $this->fail($import, 'Missing file path on import record.');
            return;
        }

        $absolutePath = $this->resolveAbsolutePath($disk, $path);
        if (! is_string($absolutePath) || $absolutePath === '' || ! is_file($absolutePath)) {
            $this->fail($import, 'Import file not found on storage disk.');
            return;
        }

        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        $info = $reader->listWorksheetInfo($absolutePath);
        $firstSheet = $info[0] ?? null;
        $totalRows = is_array($firstSheet) ? (int) ($firstSheet['totalRows'] ?? 0) : 0;
        $lastColumnLetter = is_array($firstSheet) ? (string) ($firstSheet['lastColumnLetter'] ?? 'A') : 'A';

        if ($totalRows < 1) {
            $this->fail($import, 'Spreadsheet appears to be empty.');
            return;
        }

        $headerMap = $this->readHeaderMap($reader, $absolutePath, $lastColumnLetter);
        if ($headerMap === []) {
            $this->fail($import, 'Could not read header row (row 1).');
            return;
        }

        if (! $import->awarding_institution_id) {
            $this->fail($import, 'Awarding institution is required for learner record imports.');
            return;
        }

        $institutionId = (int) $import->awarding_institution_id;

        DB::transaction(function () use ($import, $totalRows) {
            $locked = LearnerRecordImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($locked->status?->isTerminal()) {
                return;
            }
            $locked->forceFill([
                'total_rows' => max(0, $totalRows - 1),
            ])->save();
        });

        $startRow = 2;
        while ($startRow <= $totalRows) {
            $endRow = min($totalRows, $startRow + self::CHUNK_SIZE - 1);
            $chunkSize = max(0, ($endRow - $startRow) + 1);
            $chunkRows = $this->readChunkRows($reader, $absolutePath, $lastColumnLetter, $startRow, $chunkSize);

            $this->processChunk($import, $chunkRows, $headerMap, $startRow, $endRow, $institutionId);

            $startRow += self::CHUNK_SIZE;
        }

        $this->complete($import);
    }

    /**
     * @return array<string, string> map of column letter => internal field key
     */
    private function readHeaderMap(Xlsx $reader, string $absolutePath, string $lastColumnLetter): array
    {
        $filter = new ChunkReadFilter();
        $filter->setRows(1, 1);
        $reader->setReadFilter($filter);

        $spreadsheet = $reader->load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();

        $headerRow = $sheet->rangeToArray(
            'A1:'.$lastColumnLetter.'1',
            null,
            true,
            true,
            true,
        );

        $row = $headerRow[1] ?? [];
        if (! is_array($row) || $row === []) {
            return [];
        }

        $map = [];
        foreach ($row as $colLetter => $value) {
            $header = trim((string) ($value ?? ''));
            if ($header === '') {
                continue;
            }
            $key = $this->normalizeHeaderKey($header);
            $internal = $this->resolveHeaderField($key);
            if ($internal) {
                $map[(string) $colLetter] = $internal;
            }
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>> rows indexed by row number (from rangeToArray returnCellRef=true)
     */
    private function readChunkRows(Xlsx $reader, string $absolutePath, string $lastColumnLetter, int $startRow, int $chunkSize): array
    {
        $filter = new ChunkReadFilter();
        $filter->setRows($startRow, $chunkSize);
        $reader->setReadFilter($filter);

        $spreadsheet = $reader->load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();

        $endRow = $startRow + max(0, $chunkSize) - 1;

        $rows = $sheet->rangeToArray(
            'A'.$startRow.':'.$lastColumnLetter.$endRow,
            null,
            true,
            true,
            true,
        );

        $out = [];
        foreach ($rows as $rowIndex => $row) {
            if (! is_numeric($rowIndex) || ! is_array($row)) {
                continue;
            }
            $out[(int) $rowIndex] = $row;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunkRows
     * @param  array<string, string>  $headerMap
     */
    private function processChunk(LearnerRecordImport $import, array $chunkRows, array $headerMap, int $startRow, int $endRow, int $institutionId): void
    {
        $inserted = 0;
        $updated = 0;
        $failed = 0;
        $rowErrors = [];

        $prepared = [];
        $hashes = [];

        foreach ($chunkRows as $rowNumber => $row) {
            $mapped = $this->mapRow($row, $headerMap);

            $studentId = $this->stringOrNull($mapped['student_id'] ?? null);
            $certificateNo = $this->stringOrNull($mapped['certificate_no'] ?? null);
            $nrc = $this->stringOrNull($mapped['nrc_number'] ?? null);
            $passport = $this->stringOrNull($mapped['passport_no'] ?? null);
            $firstName = $this->stringOrNull($mapped['first_name'] ?? null);
            $lastName = $this->stringOrNull($mapped['last_name'] ?? null);
            $otherNames = $this->stringOrNull($mapped['other_names'] ?? null);
            $gender = $this->stringOrNull($mapped['gender'] ?? null);
            $program = $this->stringOrNull($mapped['program_of_study'] ?? null);
            $classification = $this->stringOrNull($mapped['classification'] ?? null);
            $yearAwarded = $this->intOrNull($mapped['year_awarded'] ?? null);
            $awardDate = $this->dateOrNull($mapped['award_date'] ?? null);

            if ($classification !== null && mb_strlen($classification) > 150) {
                $failed++;
                $rowErrors[] = ['row' => $rowNumber, 'message' => 'Classification must not exceed 150 characters.'];
                continue;
            }

            $studentIdNorm = LearnerRecordNormalizer::normalizeStudentId($studentId);
            $certNorm = LearnerRecordNormalizer::normalizeCertificateNo($certificateNo);
            $nrcNorm = LearnerRecordNormalizer::normalizeNrc($nrc);
            $passportNorm = LearnerRecordNormalizer::normalizePassport($passport);
            $nameNorm = LearnerRecordNormalizer::normalizeNameParts($firstName, $otherNames, $lastName);
            $titleNorm = LearnerRecordNormalizer::normalizeProgramTitle($program);

            if (! $studentIdNorm && ! $certNorm && ! $nrcNorm && ! $passportNorm) {
                $failed++;
                $rowErrors[] = ['row' => $rowNumber, 'message' => 'Missing identifiers: provide StudentID, CertificateNo, NRCNumber, or PassportNo.'];
                continue;
            }

            $hash = LearnerRecordNormalizer::dedupeHash(
                awardingInstitutionId: $institutionId,
                certificateNoNormalized: $certNorm,
                studentIdNormalized: $studentIdNorm,
                yearAwarded: $yearAwarded,
            );

            if ($hash) {
                $hashes[] = $hash;
            }

            $prepared[] = [
                'row_number' => $rowNumber,
                'awarding_institution_id' => $institutionId,
                'import_id' => (int) $import->id,
                'institution_name_raw' => null,
                'student_id' => $studentId,
                'certificate_no' => $certificateNo,
                'nrc_number' => $nrc,
                'passport_no' => $passport,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'other_names' => $otherNames,
                'gender' => $gender,
                'program_of_study' => $program,
                'qualification_title_normalized' => $titleNorm,
                'year_awarded' => $yearAwarded,
                'award_date' => $awardDate,
                'classification' => $classification,
                'source_type' => LearnerRecordSourceType::Import->value,
                'source_reference' => null,
                'raw_payload' => null,
                'nrc_normalized' => $nrcNorm,
                'passport_normalized' => $passportNorm,
                'name_normalized' => $nameNorm,
                'student_id_normalized' => $studentIdNorm,
                'certificate_no_normalized' => $certNorm,
                'dedupe_hash' => $hash,
                'is_active' => true,
                'verified_at' => null,
            ];
        }

        $existingByHash = [];
        $hashes = array_values(array_unique(array_filter($hashes, fn ($h) => is_string($h) && $h !== '')));
        if ($hashes !== []) {
            $existingByHash = LearnerRecord::query()
                ->whereIn('dedupe_hash', $hashes)
                ->get()
                ->keyBy('dedupe_hash')
                ->all();
        }

        foreach ($prepared as $rowData) {
            try {
                unset($rowData['row_number']);
                $hash = $rowData['dedupe_hash'] ?? null;
                $existing = ($hash && isset($existingByHash[$hash])) ? $existingByHash[$hash] : null;

                if ($existing instanceof LearnerRecord) {
                    $existing->fill($rowData)->save();
                    $updated++;
                } else {
                    $record = LearnerRecord::query()->create($rowData);
                    $inserted++;
                    if ($hash) {
                        $existingByHash[$hash] = $record;
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                $rowErrors[] = [
                    'row' => (int) ($rowData['row_number'] ?? 0),
                    'message' => $e->getMessage(),
                ];
            }
        }

        DB::transaction(function () use ($import, $inserted, $updated, $failed, $rowErrors, $startRow, $endRow) {
            $locked = LearnerRecordImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($locked->status?->isTerminal()) {
                return;
            }

            $errors = is_array($locked->errors) ? $locked->errors : [];
            foreach ($rowErrors as $err) {
                if (count($errors) >= self::MAX_ERROR_ROWS_STORED) {
                    break;
                }
                $errors[] = [
                    'type' => 'row',
                    'row' => (int) ($err['row'] ?? 0),
                    'message' => (string) ($err['message'] ?? 'Row error'),
                ];
            }

            $processedInChunk = max(0, ($endRow - $startRow) + 1);

            $locked->forceFill([
                'processed_rows' => (int) $locked->processed_rows + $processedInChunk,
                'inserted_rows' => (int) $locked->inserted_rows + $inserted,
                'updated_rows' => (int) $locked->updated_rows + $updated,
                'failed_rows' => (int) $locked->failed_rows + $failed,
                'errors' => $errors !== [] ? $errors : null,
            ])->save();
        });
    }

    private function complete(LearnerRecordImport $import): void
    {
        DB::transaction(function () use ($import) {
            $locked = LearnerRecordImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($locked->status?->isTerminal()) {
                return;
            }

            $status = ((int) ($locked->failed_rows ?? 0)) > 0
                ? LearnerRecordImportStatus::CompletedWithErrors
                : LearnerRecordImportStatus::Completed;

            $locked->forceFill([
                'status' => $status,
                'completed_at' => now(),
            ])->save();
        });
    }

    private function fail(LearnerRecordImport $import, string $message): void
    {
        DB::transaction(function () use ($import, $message) {
            $locked = LearnerRecordImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($locked->status?->isTerminal()) {
                return;
            }

            $errors = is_array($locked->errors) ? $locked->errors : [];
            $errors[] = ['type' => 'error', 'message' => $message];

            $locked->forceFill([
                'status' => LearnerRecordImportStatus::Failed,
                'errors' => $errors,
                'completed_at' => now(),
            ])->save();
        });
    }

    private function resolveAbsolutePath(string $disk, string $relativePath): ?string
    {
        try {
            $storage = Storage::disk($disk);
            if (method_exists($storage, 'path')) {
                return $storage->path($relativePath);
            }

            $tmp = tempnam(sys_get_temp_dir(), 'lrimport_');
            if (! is_string($tmp)) {
                return null;
            }
            file_put_contents($tmp, $storage->get($relativePath));

            return $tmp;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeHeaderKey(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '', $header) ?? $header;

        return $header;
    }

    private function resolveHeaderField(string $normalized): ?string
    {
        return match ($normalized) {
            'studentid', 'studentnumber', 'studentno' => 'student_id',
            'certificateno', 'certificatenumber' => 'certificate_no',
            'firstname', 'givenname' => 'first_name',
            'lastname', 'surname' => 'last_name',
            'othernames', 'middlename' => 'other_names',
            'gender', 'sex' => 'gender',
            'nrcnumber', 'nrc' => 'nrc_number',
            'passportno', 'passportnumber', 'passport' => 'passport_no',
            'programofstudy', 'programmeofstudy', 'program', 'programme' => 'program_of_study',
            'yearawarded', 'awardyear', 'year' => 'year_awarded',
            'classification', 'qualificationclassification', 'awardclassification', 'class', 'awardclass' => 'classification',
            'awarddate', 'dateawarded' => 'award_date',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $headerMap
     * @return array<string, mixed>
     */
    private function mapRow(array $row, array $headerMap): array
    {
        $out = [];
        foreach ($headerMap as $colLetter => $key) {
            $out[$key] = $row[$colLetter] ?? null;
        }

        return $out;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $t = trim($value);
            return $t === '' ? null : $t;
        }
        if (is_int($value) || is_float($value)) {
            $s = trim((string) $value);
            return $s === '' ? null : $s;
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (! preg_match('/^-?\\d+$/', $s)) {
            return null;
        }

        $i = (int) $s;
        return $i > 0 ? $i : null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value) || is_float($value)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject($value);
                return $dt->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        $ts = strtotime($s);
        if (! $ts) {
            return null;
        }

        return date('Y-m-d', $ts);
    }
}
