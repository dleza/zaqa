<?php

namespace App\Support\Imports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExcelTemplateDownload
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $exampleRows
     */
    public static function stream(string $downloadFileName, array $headers, array $exampleRows = []): StreamedResponse
    {
        return new StreamedResponse(function () use ($headers, $exampleRows) {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([$headers], null, 'A1');
            if ($exampleRows !== []) {
                $sheet->fromArray($exampleRows, null, 'A2');
            }
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$downloadFileName.'"',
        ]);
    }
}
