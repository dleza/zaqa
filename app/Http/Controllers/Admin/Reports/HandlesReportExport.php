<?php

namespace App\Http\Controllers\Admin\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait HandlesReportExport
{
    /**
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function exportCsv(iterable $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function exportXlsx(iterable $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $rowNum = 1;
            foreach ($rows as $row) {
                $sheet->fromArray([array_values($row)], null, 'A'.$rowNum, true);
                $rowNum++;
                if ($rowNum > 50001) {
                    break;
                }
            }
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function exportPdf(string $view, array $data, string $filename): Response
    {
        return Pdf::loadView($view, $data)->download($filename);
    }
}
