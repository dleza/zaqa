<?php

namespace Tests\Feature;

use App\Domain\LearnerRecords\LearnerRecordExcelImportProcessor;
use App\Domain\LearnerRecords\LearnerRecordImportService;
use App\Enums\LearnerRecordImportStatus;
use App\Enums\LearnerRecordSourceType;
use App\Jobs\LearnerRecords\ProcessLearnerRecordImportJob;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\LearnerRecord;
use App\Models\LearnerRecordImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class LearnerRecordImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeXlsx(array $headers, array $rows): UploadedFile
    {
        $sheet = (new Spreadsheet())->getActiveSheet();

        foreach (array_values($headers) as $i => $header) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col.'1', $header);
        }

        $rowNum = 2;
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $val) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue($col.$rowNum, $val);
            }
            $rowNum++;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'lrimport_');
        $path = is_string($tmpPath) ? $tmpPath.'.xlsx' : sys_get_temp_dir().'/lrimport.xlsx';

        $writer = new Xlsx($sheet->getParent());
        $writer->save($path);

        return new UploadedFile(
            path: $path,
            originalName: 'learner_records.xlsx',
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            error: null,
            test: true,
        );
    }

    public function test_import_service_saves_file_and_dispatches_job(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->activated()->create();

        $file = $this->makeXlsx(
            headers: ['StudentID', 'CertificateNo', 'FirstName', 'LastName', 'ProgramOfStudy', 'YearAwarded'],
            rows: [
                ['S-001', 'C-001', 'John', 'Doe', 'Diploma in Testing', 2024],
            ],
        );

        $import = app(LearnerRecordImportService::class)->createAndDispatch($file, $user, null);

        $this->assertDatabaseHas('learner_record_imports', [
            'id' => $import->id,
            'original_filename' => 'learner_records.xlsx',
            'status' => LearnerRecordImportStatus::Pending->value,
        ]);

        Storage::disk('local')->assertExists($import->file_path);

        Queue::assertPushed(ProcessLearnerRecordImportJob::class, fn ($job) => (int) $job->importId === (int) $import->id);
    }

    public function test_processor_inserts_updates_and_records_row_errors(): void
    {
        Storage::fake('local');

        $country = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Test University',
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $file = $this->makeXlsx(
            headers: ['StudentID', 'CertificateNo', 'FirstName', 'LastName', 'ProgramOfStudy', 'YearAwarded'],
            rows: [
                ['S-001', 'C-001', 'John', 'Doe', 'Program A', 2024],
                // Same dedupe hash (inst+cert) updates existing record.
                ['S-001', 'C-001', 'John', 'Doe', 'Program A (Updated)', 2024],
                // Missing all identifiers -> row error
                ['', '', 'Jane', 'Doe', 'Program B', 2024],
            ],
        );

        $storedPath = 'private/learner-record-imports/test.xlsx';
        Storage::disk('local')->put($storedPath, file_get_contents($file->getRealPath()));

        $import = LearnerRecordImport::query()->create([
            'uploaded_by_user_id' => null,
            'awarding_institution_id' => $institution->id,
            'file_path' => $storedPath,
            'original_filename' => 'test.xlsx',
            'status' => LearnerRecordImportStatus::Pending,
            'total_rows' => null,
            'processed_rows' => 0,
            'inserted_rows' => 0,
            'updated_rows' => 0,
            'failed_rows' => 0,
            'errors' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        app(LearnerRecordExcelImportProcessor::class)->process($import);

        $import->refresh();
        $this->assertSame(LearnerRecordImportStatus::CompletedWithErrors, $import->status);
        $this->assertSame(3, (int) $import->total_rows);
        $this->assertSame(3, (int) $import->processed_rows);
        $this->assertSame(1, (int) $import->inserted_rows);
        $this->assertSame(1, (int) $import->updated_rows);
        $this->assertSame(1, (int) $import->failed_rows);
        $this->assertIsArray($import->errors);
        $this->assertNotEmpty($import->errors);

        $this->assertSame(1, LearnerRecord::query()->count());

        $record = LearnerRecord::query()->firstOrFail();
        $this->assertSame($institution->id, (int) $record->awarding_institution_id);
        $this->assertSame('S-001', $record->student_id);
        $this->assertSame('C-001', $record->certificate_no);
        $this->assertSame('Program A (Updated)', $record->program_of_study);
        $this->assertSame(2024, (int) $record->year_awarded);
        $this->assertSame(LearnerRecordSourceType::Import, $record->source_type);
    }
}
