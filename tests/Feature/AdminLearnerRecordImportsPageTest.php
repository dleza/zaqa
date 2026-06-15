<?php

namespace Tests\Feature;

use App\Enums\LearnerRecordImportStatus;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\LearnerRecordImport;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AdminLearnerRecordImportsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_authorized_admin_can_view_import_history_with_uploader_details(): void
    {
        $viewer = User::factory()->activated()->create(['applicant_type' => null]);
        $viewer->givePermissionTo(['dashboard.view', 'learner_records.view', 'learner_records.import']);

        $uploader = User::factory()->activated()->create(['applicant_type' => null, 'name' => 'Import Officer']);

        $import = LearnerRecordImport::query()->create([
            'uploaded_by_user_id' => $uploader->id,
            'awarding_institution_id' => null,
            'file_path' => 'private/learner-record-imports/uploads/test.xlsx',
            'original_filename' => 'HE Learner Records Template.xlsx',
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

        $this->actingAs($viewer)
            ->get('/admin/learner-records/imports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LearnerRecords/Imports/Index')
                ->where('can.import', true)
                ->where('imports.data.0.id', $import->id)
                ->where('imports.data.0.original_filename', $import->original_filename)
                ->where('imports.data.0.uploaded_by.name', 'Import Officer')
            );
    }

    public function test_authorized_admin_can_download_import_template(): void
    {
        $viewer = User::factory()->activated()->create(['applicant_type' => null]);
        $viewer->givePermissionTo(['dashboard.view', 'learner_records.view']);

        $response = $this->actingAs($viewer)
            ->get('/admin/learner-records/imports/template');

        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmp = tempnam(sys_get_temp_dir(), 'lr-template_').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        $headers = [];
        foreach (range('A', 'L') as $col) {
            $value = trim((string) $sheet->getCell($col.'1')->getValue());
            if ($value !== '') {
                $headers[] = $value;
            }
        }

        $this->assertContains('Classification', $headers);
        $this->assertSame('Merit', trim((string) $sheet->getCell('K2')->getValue()));
    }

    public function test_learner_record_show_displays_classification_when_present(): void
    {
        $viewer = User::factory()->activated()->create(['applicant_type' => null]);
        $viewer->givePermissionTo(['dashboard.view', 'learner_records.view']);

        $record = \App\Models\LearnerRecord::query()->create([
            'student_id' => 'S-SHOW',
            'certificate_no' => 'C-SHOW',
            'first_name' => 'Show',
            'last_name' => 'Me',
            'program_of_study' => 'Diploma',
            'year_awarded' => 2024,
            'classification' => 'Credit',
            'source_type' => \App\Enums\LearnerRecordSourceType::Import->value,
            'is_active' => true,
        ]);

        $this->actingAs($viewer)
            ->get('/admin/learner-records/records/'.$record->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LearnerRecords/Show')
                ->where('record.classification', 'Credit')
            );
    }

    public function test_upload_requires_awarding_institution(): void
    {
        $importer = User::factory()->activated()->create(['applicant_type' => null]);
        $importer->givePermissionTo(['dashboard.view', 'learner_records.view', 'learner_records.import']);

        $country = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Upload Test University',
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setCellValue('A1', 'StudentID');
        $sheet->setCellValue('A2', 'S-001');
        $tmp = tempnam(sys_get_temp_dir(), 'lrimport_').'.xlsx';
        (new Xlsx($sheet->getParent()))->save($tmp);

        $file = new UploadedFile(
            path: $tmp,
            originalName: 'learner_records.xlsx',
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            error: null,
            test: true,
        );

        $this->actingAs($importer)
            ->post('/admin/learner-records/imports', ['file' => $file])
            ->assertSessionHasErrors('awarding_institution_id');

        $this->actingAs($importer)
            ->post('/admin/learner-records/imports', [
                'awarding_institution_id' => $institution->id,
                'file' => $file,
            ])
            ->assertRedirect();
    }
}
