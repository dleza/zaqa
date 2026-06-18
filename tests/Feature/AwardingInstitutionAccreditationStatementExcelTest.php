<?php

namespace Tests\Feature;

use App\Models\AwardingInstitution;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AwardingInstitutionAccreditationStatementExcelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo([
            'dashboard.view',
            'settings.awarding_institutions.view',
            'settings.awarding_institutions.edit',
        ]);

        return $user;
    }

    private function makeViewer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view', 'settings.awarding_institutions.view']);

        return $user;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function makeXlsx(array $headers, array $rows): UploadedFile
    {
        $sheet = (new Spreadsheet)->getActiveSheet();

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

        $tmpPath = tempnam(sys_get_temp_dir(), 'ai_accred_');
        $path = is_string($tmpPath) ? $tmpPath.'.xlsx' : sys_get_temp_dir().'/ai_accred.xlsx';

        $writer = new Xlsx($sheet->getParent());
        $writer->save($path);

        return new UploadedFile(
            path: $path,
            originalName: 'accreditation-statements.xlsx',
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            error: null,
            test: true,
        );
    }

    /**
     * @return array{Country, AwardingInstitution, AwardingInstitution}
     */
    private function seedInstitutions(): array
    {
        $country = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $withStatement = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'University With Statement',
            'is_active' => true,
            'sort_order' => 0,
            'accreditation_statement' => 'Existing statement.',
            'accreditation_statement_source' => 'manual',
        ]);

        $withoutStatement = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'University Without Statement',
            'is_active' => true,
            'sort_order' => 1,
            'accreditation_statement' => null,
        ]);

        return [$country, $withStatement, $withoutStatement];
    }

    public function test_admin_can_download_accreditation_statement_excel(): void
    {
        [, $withStatement, $withoutStatement] = $this->seedInstitutions();

        $response = $this->actingAs($this->makeAdmin())
            ->get(route('admin.settings.awarding_institutions.accreditation_statements.export'))
            ->assertOk();

        $this->assertStringContainsString(
            'awarding-institution-accreditation-statements.xlsx',
            (string) $response->headers->get('content-disposition'),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'export_');
        $path = is_string($tmp) ? $tmp.'.xlsx' : sys_get_temp_dir().'/export.xlsx';
        file_put_contents($path, $response->streamedContent());

        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        $this->assertSame('institution_id', strtolower(str_replace(' ', '_', (string) $sheet[0][0])));
        $this->assertGreaterThanOrEqual(3, count($sheet));

        $ids = array_map(fn ($row) => (int) ($row[0] ?? 0), array_slice($sheet, 1));
        $this->assertContains($withStatement->id, $ids);
        $this->assertContains($withoutStatement->id, $ids);
    }

    public function test_missing_only_export_excludes_institutions_with_statements(): void
    {
        [, $withStatement, $withoutStatement] = $this->seedInstitutions();

        $response = $this->actingAs($this->makeAdmin())
            ->get(route('admin.settings.awarding_institutions.accreditation_statements.export', ['missing_only' => '1']))
            ->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'export_missing_');
        $path = is_string($tmp) ? $tmp.'.xlsx' : sys_get_temp_dir().'/export_missing.xlsx';
        file_put_contents($path, $response->streamedContent());

        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        $ids = array_map(fn ($row) => (int) ($row[0] ?? 0), array_slice($sheet, 1));

        $this->assertContains($withoutStatement->id, $ids);
        $this->assertNotContains($withStatement->id, $ids);
    }

    public function test_admin_can_import_statements_for_blank_institutions_by_id(): void
    {
        [, , $withoutStatement] = $this->seedInstitutions();

        $file = $this->makeXlsx(
            ['institution_id', 'new_accreditation_statement'],
            [[$withoutStatement->id, 'Imported via Excel.']],
        );

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.settings.awarding_institutions.accreditation_statements.import'), [
                'file' => $file,
                'overwrite_existing' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $withoutStatement->refresh();
        $this->assertSame('Imported via Excel.', $withoutStatement->accreditation_statement);
        $this->assertSame('import', $withoutStatement->accreditation_statement_source);
        $this->assertNotNull($withoutStatement->accreditation_statement_updated_by_user_id);
        $this->assertNotNull($withoutStatement->accreditation_statement_updated_at);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'awarding_institution.accreditation_statement_imported',
            'entity_id' => $withoutStatement->id,
        ]);
    }

    public function test_import_skips_empty_new_statement_rows(): void
    {
        [, , $withoutStatement] = $this->seedInstitutions();

        $file = $this->makeXlsx(
            ['institution_id', 'new_accreditation_statement'],
            [[$withoutStatement->id, '']],
        );

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.settings.awarding_institutions.accreditation_statements.import'), [
                'file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $withoutStatement->refresh();
        $this->assertNull($withoutStatement->accreditation_statement);
    }

    public function test_import_does_not_overwrite_existing_statements_by_default(): void
    {
        [, $withStatement] = $this->seedInstitutions();

        $file = $this->makeXlsx(
            ['institution_id', 'new_accreditation_statement'],
            [[$withStatement->id, 'Attempted overwrite.']],
        );

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.settings.awarding_institutions.accreditation_statements.import'), [
                'file' => $file,
                'overwrite_existing' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $withStatement->refresh();
        $this->assertSame('Existing statement.', $withStatement->accreditation_statement);
    }

    public function test_import_overwrites_existing_when_requested(): void
    {
        [, $withStatement] = $this->seedInstitutions();

        $file = $this->makeXlsx(
            ['institution_id', 'new_accreditation_statement'],
            [[$withStatement->id, 'Overwritten via Excel.']],
        );

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.settings.awarding_institutions.accreditation_statements.import'), [
                'file' => $file,
                'overwrite_existing' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $withStatement->refresh();
        $this->assertSame('Overwritten via Excel.', $withStatement->accreditation_statement);
        $this->assertSame('import', $withStatement->accreditation_statement_source);
    }

    public function test_import_reports_unknown_institution_id(): void
    {
        $file = $this->makeXlsx(
            ['institution_id', 'new_accreditation_statement'],
            [[99999, 'Should not apply.']],
        );

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.settings.awarding_institutions.accreditation_statements.import'), [
                'file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('import_report');

        $report = session('import_report');
        $this->assertIsArray($report);
        $this->assertNotEmpty($report['errors'] ?? []);
    }

    public function test_import_rejects_missing_required_columns(): void
    {
        $file = $this->makeXlsx(['institution_id'], [[1]]);

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.settings.awarding_institutions.accreditation_statements.import'), [
                'file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('import_report');

        $report = session('import_report');
        $this->assertStringContainsString('Missing required column', (string) (($report['errors'][0] ?? '')));
    }

    public function test_import_rejects_statement_over_max_length(): void
    {
        [, , $withoutStatement] = $this->seedInstitutions();

        $file = $this->makeXlsx(
            ['institution_id', 'new_accreditation_statement'],
            [[$withoutStatement->id, str_repeat('A', 5001)]],
        );

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.settings.awarding_institutions.accreditation_statements.import'), [
                'file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('import_report');

        $withoutStatement->refresh();
        $this->assertNull($withoutStatement->accreditation_statement);
    }

    public function test_import_can_match_by_institution_name_and_country_when_id_missing(): void
    {
        [$country, , $withoutStatement] = $this->seedInstitutions();

        $file = $this->makeXlsx(
            ['institution_name', 'country', 'new_accreditation_statement'],
            [['University Without Statement', $country->name, 'Matched by name.']],
        );

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.settings.awarding_institutions.accreditation_statements.import'), [
                'file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $withoutStatement->refresh();
        $this->assertSame('Matched by name.', $withoutStatement->accreditation_statement);
    }

    public function test_viewer_can_export_but_cannot_import(): void
    {
        $this->seedInstitutions();

        $this->actingAs($this->makeViewer())
            ->get(route('admin.settings.awarding_institutions.accreditation_statements.export'))
            ->assertOk();

        $file = $this->makeXlsx(
            ['institution_id', 'new_accreditation_statement'],
            [[1, 'Nope.']],
        );

        $this->actingAs($this->makeViewer())
            ->post(route('admin.settings.awarding_institutions.accreditation_statements.import'), [
                'file' => $file,
            ])
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_export(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo('dashboard.view');

        $this->actingAs($user)
            ->get(route('admin.settings.awarding_institutions.accreditation_statements.export'))
            ->assertForbidden();
    }

    public function test_imported_statement_is_used_for_certificate_resolution(): void
    {
        [, , $withoutStatement] = $this->seedInstitutions();

        $file = $this->makeXlsx(
            ['institution_id', 'new_accreditation_statement'],
            [[$withoutStatement->id, 'Imported certificate statement.']],
        );

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.settings.awarding_institutions.accreditation_statements.import'), [
                'file' => $file,
            ])
            ->assertRedirect();

        $withoutStatement->refresh();
        $this->assertSame('Imported certificate statement.', $withoutStatement->accreditation_statement);

        $resolved = app(\App\Domain\Settings\AwardingInstitutionAccreditationStatementService::class)
            ->defaultForLevel1Prefill(
                \App\Models\Qualification::query()->make([
                    'awarding_institution_id' => $withoutStatement->id,
                    'level1_accreditation_statement' => null,
                ])
            );

        $this->assertSame('Imported certificate statement.', $resolved);
    }
}
