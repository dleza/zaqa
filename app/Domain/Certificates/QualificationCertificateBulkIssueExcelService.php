<?php

namespace App\Domain\Certificates;

use App\Domain\Settings\DataImportResult;
use App\Models\Qualification;
use App\Models\User;
use App\Support\Imports\SpreadsheetLoader;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class QualificationCertificateBulkIssueExcelService
{
    public function __construct(
        private readonly QualificationCertificateService $certificates,
    ) {}

    public function import(UploadedFile $file, User $user): DataImportResult
    {
        $parsed = SpreadsheetLoader::readAssociative(
            $file,
            ['qualification_id'],
            [
                'qualification' => 'qualification_id',
                'qual_id' => 'qualification_id',
            ],
        );

        $result = new DataImportResult;
        if ($parsed['header_errors'] !== []) {
            $result->errors = $parsed['header_errors'];

            return $result;
        }

        foreach ($parsed['rows'] as $row) {
            $line = (int) ($row['_line'] ?? 0);
            unset($row['_line']);

            $qid = $row['qualification_id'] ?? null;
            if ($qid === null || $qid === '' || ! is_numeric($qid)) {
                $result->errors[] = "Row {$line}: qualification_id is required.";

                continue;
            }

            $qualification = Qualification::query()->find((int) $qid);
            if (! $qualification) {
                $result->errors[] = "Row {$line}: qualification ".(int) $qid.' not found.';

                continue;
            }

            try {
                $this->certificates->issue($qualification, $user, false);
                $result->created++;
            } catch (ValidationException $e) {
                $msg = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                $result->errors[] = "Row {$line}: {$msg}";
            }
        }

        return $result;
    }
}
