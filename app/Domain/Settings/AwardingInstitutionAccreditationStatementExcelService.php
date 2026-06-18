<?php

namespace App\Domain\Settings;

use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\User;
use App\Support\Imports\SpreadsheetLoader;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AwardingInstitutionAccreditationStatementExcelService
{
  private const STATEMENT_MAX_LENGTH = 5000;

  /** @var array<string, string> */
  private const HEADER_ALIASES = [
      'id' => 'institution_id',
      'institution' => 'institution_name',
      'name' => 'institution_name',
      'country_name' => 'country',
      'current_statement' => 'current_accreditation_statement',
      'new_statement' => 'new_accreditation_statement',
      'accreditation_statement' => 'new_accreditation_statement',
  ];

  /** @var list<string> */
  private const EXPORT_HEADERS = [
      'institution_id',
      'institution_name',
      'country',
      'status',
      'current_accreditation_statement',
      'new_accreditation_statement',
  ];

  public function __construct(
      private readonly AwardingInstitutionAccreditationStatementService $accreditationStatements,
  ) {}

  public function export(bool $missingOnly): StreamedResponse
  {
      $fileName = $missingOnly
          ? 'awarding-institution-accreditation-statements-missing.xlsx'
          : 'awarding-institution-accreditation-statements.xlsx';

      $institutions = $this->institutionsForExport($missingOnly);

      return new StreamedResponse(function () use ($institutions) {
          $spreadsheet = new Spreadsheet;
          $sheet = $spreadsheet->getActiveSheet();
          $sheet->fromArray([self::EXPORT_HEADERS], null, 'A1');

          $rows = [];
          foreach ($institutions as $institution) {
              $rows[] = [
                  $institution->id,
                  $institution->name,
                  $institution->country?->name ?? '',
                  $institution->is_active ? 'Active' : 'Inactive',
                  (string) ($institution->accreditation_statement ?? ''),
                  '',
              ];
          }

          if ($rows !== []) {
              $sheet->fromArray($rows, null, 'A2');
          }

          $writer = new Xlsx($spreadsheet);
          $writer->save('php://output');
      }, 200, [
          'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
      ]);
  }

  public function import(UploadedFile $file, User $actor, bool $overwriteExisting = false): AccreditationStatementExcelImportSummary
  {
      $summary = new AccreditationStatementExcelImportSummary;

      $parsed = SpreadsheetLoader::readAssociative(
          $file,
          ['new_accreditation_statement'],
          self::HEADER_ALIASES,
      );

      if ($parsed['header_errors'] !== []) {
          $summary->errors = $parsed['header_errors'];

          return $summary;
      }

      foreach ($parsed['rows'] as $row) {
          $summary->total_processed++;
          $line = (int) ($row['_line'] ?? 0);
          unset($row['_line']);

          $newStatement = trim((string) ($row['new_accreditation_statement'] ?? ''));
          if ($newStatement === '') {
              $summary->skipped_empty++;

              continue;
          }

          if (strlen($newStatement) > self::STATEMENT_MAX_LENGTH) {
              $summary->invalid_rows++;
              $summary->errors[] = "Row {$line}: accreditation statement exceeds ".self::STATEMENT_MAX_LENGTH.' characters.';

              continue;
          }

          $institution = $this->resolveInstitution($row);
          if (! $institution instanceof AwardingInstitution) {
              $summary->not_found++;
              $summary->errors[] = "Row {$line}: awarding institution not found (check institution_id or institution_name + country).";

              continue;
          }

          $existing = trim((string) ($institution->accreditation_statement ?? ''));
          if ($existing !== '' && ! $overwriteExisting) {
              $summary->skipped_existing++;

              continue;
          }

          if ($existing === $newStatement) {
              $summary->skipped_existing++;

              continue;
          }

          $this->accreditationStatements->applyImportedStatementUpdate(
              $institution,
              $actor,
              $newStatement,
              $overwriteExisting,
              $line,
          );
          $summary->updated++;
      }

      return $summary;
  }

  /**
   * @return \Illuminate\Database\Eloquent\Collection<int, AwardingInstitution>
   */
  private function institutionsForExport(bool $missingOnly)
  {
      return AwardingInstitution::query()
          ->with('country')
          ->when($missingOnly, fn ($q) => $q->where(function ($query) {
              $query->whereNull('accreditation_statement')
                  ->orWhere('accreditation_statement', '');
          }))
          ->join('countries', 'countries.id', '=', 'awarding_institutions.country_id')
          ->orderBy('countries.name')
          ->orderBy('awarding_institutions.name')
          ->select('awarding_institutions.*')
          ->get();
  }

  /**
   * @param  array<string, mixed>  $row
   */
  private function resolveInstitution(array $row): ?AwardingInstitution
  {
      $id = (int) ($row['institution_id'] ?? 0);
      if ($id > 0) {
          return AwardingInstitution::query()->find($id);
      }

      $name = $this->normalizeInstitutionName((string) ($row['institution_name'] ?? ''));
      $countryRef = trim((string) ($row['country'] ?? ''));
      if ($name === '' || $countryRef === '') {
          return null;
      }

      $country = Country::query()
          ->where(function ($q) use ($countryRef) {
              $q->whereRaw('LOWER(name) = ?', [strtolower($countryRef)])
                  ->orWhere('iso_code', strtoupper($countryRef));
          })
          ->first();

      if (! $country instanceof Country) {
          return null;
      }

      return AwardingInstitution::query()
          ->where('country_id', $country->id)
          ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
          ->first();
  }

  private function normalizeInstitutionName(string $name): string
  {
      $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

      return strtolower($name);
  }
}
