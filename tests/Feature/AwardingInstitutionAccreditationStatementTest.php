<?php

namespace Tests\Feature;

use App\Domain\Settings\AwardingInstitutionAccreditationStatementService;
use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AwardingInstitutionAccreditationStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed([BillingCategoriesSeeder::class, QualificationTypesSeeder::class, FeeStructuresSeeder::class]);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo([
            'dashboard.view',
            'settings.awarding_institutions.view',
            'settings.awarding_institutions.create',
            'settings.awarding_institutions.edit',
        ]);

        return $user;
    }

    private function makeLevel1(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');
        $user->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        return $user;
    }

    private function makeLevel2(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');
        $user->givePermissionTo([
            'verification.pool.view',
            'verification.decide.approve',
            'verification.decide.reject',
            'verification.certificate.issue',
        ]);

        return $user;
    }

    /**
     * @return array{Country, AwardingInstitution, Qualification, Application}
     */
    private function makeQualificationWithInstitution(?string $institutionStatement = null): array
    {
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1],
        );
        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Test University',
            'is_active' => true,
            'sort_order' => 0,
            'accreditation_statement' => $institutionStatement,
            'accreditation_statement_source' => $institutionStatement ? 'manual' : null,
        ]);
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-AIS-'.Str::upper(Str::random(5)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel1Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'paid_at' => now(),
        ]);
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'country_id' => $country->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'Jane Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '123456/78/9',
            'title_of_qualification' => $type->name,
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::AssignedToLevel1,
            'assigned_verifier_id' => null,
        ]);

        return [$country, $institution, $qualification, $application];
    }

    public function test_admin_can_store_accreditation_statement_on_institution(): void
    {
        $admin = $this->makeAdmin();
        $country = Country::query()->firstOrCreate(['iso_code' => 'ZMB'], ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]);

        $this->actingAs($admin)
            ->post(route('admin.settings.awarding_institutions.store'), [
                'country_id' => $country->id,
                'name' => 'New Institution',
                'is_active' => true,
                'sort_order' => 0,
                'accreditation_statement' => 'Registered under the University Act.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('awarding_institutions', [
            'name' => 'New Institution',
            'accreditation_statement' => 'Registered under the University Act.',
            'accreditation_statement_source' => 'manual',
        ]);
    }

    public function test_show_qualification_payload_includes_institution_accreditation_statement(): void
    {
        [, $institution, $qualification] = $this->makeQualificationWithInstitution('Institution default statement.');
        $qualification->forceFill([
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => $this->makeLevel2()->id,
        ])->save();
        $l2 = $this->makeLevel2();

        $this->actingAs($l2)
            ->get(route('admin.verification.qualifications.show', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.awarding_institution_accreditation_statement', 'Institution default statement.')
                ->where('qualification.awarding_institution_has_accreditation_statement', true));
    }

    public function test_level1_auto_saves_statement_to_blank_institution(): void
    {
        [, $institution, $qualification] = $this->makeQualificationWithInstitution(null);
        $level1 = $this->makeLevel1();
        $qualification->forceFill([
            'assigned_verifier_id' => $level1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ])->save();
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        app(QualificationLevel1ReviewService::class)->completeLevel1(
            $qualification,
            $level1,
            'All checks passed.',
            true,
            (int) $type->id,
            'Saved from Level 1 submission.',
            UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'),
            UploadedFile::fake()->create('eval.pdf', 50, 'application/pdf'),
        );

        $institution->refresh();
        $this->assertSame('Saved from Level 1 submission.', $institution->accreditation_statement);
        $this->assertSame('level1_submission', $institution->accreditation_statement_source);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'awarding_institution.accreditation_statement_auto_saved_from_level1',
            'entity_id' => $institution->id,
        ]);
    }

    public function test_level1_does_not_overwrite_existing_institution_statement(): void
    {
        [, $institution, $qualification] = $this->makeQualificationWithInstitution('Existing institution statement.');
        $level1 = $this->makeLevel1();
        $qualification->forceFill([
            'assigned_verifier_id' => $level1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ])->save();
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        app(QualificationLevel1ReviewService::class)->completeLevel1(
            $qualification,
            $level1,
            'All checks passed.',
            true,
            (int) $type->id,
            'Different Level 1 statement.',
            UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'),
            UploadedFile::fake()->create('eval.pdf', 50, 'application/pdf'),
        );

        $institution->refresh();
        $qualification->refresh();
        $this->assertSame('Existing institution statement.', $institution->accreditation_statement);
        $this->assertSame('Different Level 1 statement.', $qualification->level1_accreditation_statement);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'awarding_institution.accreditation_statement_mismatch',
            'entity_id' => $institution->id,
        ]);
    }

    public function test_certificate_uses_institution_statement_when_no_level1_statement(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $institution, $qualification, $application] = $this->makeQualificationWithInstitution('Institution certificate statement.');
        $qualification->forceFill([
            'verification_state' => VerificationState::ApprovedForCertificate,
            'level1_recommended_for_award' => null,
            'level1_accreditation_statement' => null,
        ])->save();

        $application->load('qualifications');
        $required = app(\App\Domain\Fees\QualificationFeeResolver::class)->totalVerificationFeesCents($application);
        Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => null,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => $required,
            'provider' => 'test',
            'confirmed_at' => now(),
        ]);

        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->andReturnSelf();
        $pdfMock->shouldReceive('output')->andReturn('%PDF-test');
        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function (string $view, array $data) {
                return ($data['recognition_statement'] ?? '') === 'Institution certificate statement.';
            })
            ->andReturn($pdfMock);

        $admin = $this->makeLevel2();
        $this->actingAs($admin)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification), [])
            ->assertRedirect();

        $cert = $qualification->fresh()->certificates()->latest('id')->first();
        $this->assertSame('Institution certificate statement.', $cert->metadata['accreditation_statement'] ?? null);
        $this->assertSame('awarding_institution', $cert->metadata['accreditation_statement_source'] ?? null);
        $this->assertSame($institution->id, $cert->metadata['awarding_institution_id'] ?? null);
    }

    public function test_level2_approval_auto_saves_statement_to_blank_institution(): void
    {
        [, $institution, $qualification] = $this->makeQualificationWithInstitution(null);
        $l2 = $this->makeLevel2();
        $qualification->forceFill([
            'verification_state' => VerificationState::UnderLevel2Review,
            'reviewer_notes' => 'Findings here.',
            'level2_review_owner_id' => $l2->id,
        ])->save();

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.approve', $qualification), [
                'findings' => 'Findings here.',
                'accreditation_statement' => 'Saved during Level 2 approval.',
                'issue_certificate' => false,
            ])
            ->assertRedirect();

        $institution->refresh();
        $this->assertSame('Saved during Level 2 approval.', $institution->accreditation_statement);
        $this->assertSame('level2_approval', $institution->accreditation_statement_source);
    }

    public function test_resolver_priority_prefers_level1_over_institution(): void
    {
        [, , $qualification] = $this->makeQualificationWithInstitution('Institution statement.');
        $qualification->forceFill([
            'level1_accreditation_statement' => 'Level 1 statement.',
            'level1_recommended_for_award' => true,
        ])->save();

        $resolved = app(AwardingInstitutionAccreditationStatementService::class)->resolveForCertificate($qualification->fresh());
        $this->assertSame('Level 1 statement.', $resolved['statement']);
        $this->assertSame(AwardingInstitutionAccreditationStatementService::CERT_SOURCE_LEVEL1_SUBMISSION, $resolved['source']);
    }

    public function test_admin_can_update_accreditation_statement_on_institution(): void
    {
        $admin = $this->makeAdmin();
        [, $institution] = $this->makeQualificationWithInstitution(null);

        $this->actingAs($admin)
            ->put(route('admin.settings.awarding_institutions.update', $institution), [
                'country_id' => $institution->country_id,
                'name' => $institution->name,
                'is_active' => true,
                'sort_order' => 0,
                'accreditation_statement' => 'Updated accreditation text.',
            ])
            ->assertRedirect();

        $institution->refresh();
        $this->assertSame('Updated accreditation text.', $institution->accreditation_statement);
        $this->assertSame('manual', $institution->accreditation_statement_source);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'awarding_institution.accreditation_statement_updated',
            'entity_id' => $institution->id,
        ]);
    }

    public function test_index_filter_missing_accreditation_statement(): void
    {
        $admin = $this->makeAdmin();
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1],
        );
        AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'University With Statement',
            'is_active' => true,
            'sort_order' => 0,
            'accreditation_statement' => 'Has statement.',
            'accreditation_statement_source' => 'manual',
        ]);
        $withoutStatement = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'University Without Statement',
            'is_active' => true,
            'sort_order' => 1,
            'accreditation_statement' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.settings.awarding_institutions.index', ['missing_statement' => '1']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('institutions.data', 1)
                ->where('institutions.data.0.id', $withoutStatement->id)
                ->where('institutions.data.0.has_accreditation_statement', false));
    }

    public function test_resolver_uses_config_fallback_when_no_statement_exists(): void
    {
        [, , $qualification] = $this->makeQualificationWithInstitution(null);
        $qualification->forceFill(['level1_accreditation_statement' => null])->save();

        config(['certificates.recognition_act_clause' => 'Fallback recognition clause.']);

        $resolved = app(AwardingInstitutionAccreditationStatementService::class)->resolveForCertificate($qualification->fresh());
        $this->assertSame('Fallback recognition clause.', $resolved['statement']);
        $this->assertSame(AwardingInstitutionAccreditationStatementService::CERT_SOURCE_CONFIG_FALLBACK, $resolved['source']);
    }

    public function test_level2_does_not_overwrite_existing_institution_statement(): void
    {
        [, $institution, $qualification] = $this->makeQualificationWithInstitution('Existing institution statement.');
        $l2 = $this->makeLevel2();
        $qualification->forceFill([
            'verification_state' => VerificationState::UnderLevel2Review,
            'reviewer_notes' => 'Findings here.',
            'level2_review_owner_id' => $l2->id,
        ])->save();

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.approve', $qualification), [
                'findings' => 'Findings here.',
                'accreditation_statement' => 'Different Level 2 statement.',
                'issue_certificate' => false,
            ])
            ->assertRedirect();

        $institution->refresh();
        $qualification->refresh();
        $this->assertSame('Existing institution statement.', $institution->accreditation_statement);
        $this->assertSame('Different Level 2 statement.', $qualification->level1_accreditation_statement);
    }
}
