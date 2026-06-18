<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QualificationEditPageEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed([BillingCategoriesSeeder::class, QualificationTypesSeeder::class, FeeStructuresSeeder::class]);
    }

    private function makeApplication(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-EDT-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'paid_at' => now(),
        ]);
    }

    private function makeQualification(Application $application, array $overrides = []): Qualification
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1],
        );
        $inst = AwardingInstitution::query()->firstOrCreate(
            ['country_id' => $country->id, 'name' => 'Test University'],
            ['is_active' => true, 'sort_order' => 1],
        );

        return Qualification::query()->create(array_merge([
            'application_id' => $application->id,
            'country_id' => $country->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'names_as_on_qualification_document' => 'JANE DOE',
            'country_name_other' => null,
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-ORIG',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::UnderLevel2Review,
        ], $overrides));
    }

    private function basePayload(Qualification $qualification): array
    {
        return [
            'qualification_holder_name' => $qualification->qualification_holder_name,
            'names_as_on_qualification_document' => $qualification->names_as_on_qualification_document,
            'nrc_passport_number' => $qualification->nrc_passport_number,
            'country_id' => $qualification->country_id,
            'country_name_other' => $qualification->country_name_other,
            'awarding_institution_id' => $qualification->awarding_institution_id,
            'awarding_institution_name_other' => null,
            'awarding_institution_name' => $qualification->awarding_institution_name,
            'certificate_number' => $qualification->certificate_number,
            'student_number' => null,
            'examination_number' => null,
            'title_of_qualification' => $qualification->title_of_qualification,
            'award_date' => $qualification->award_date?->format('Y-m-d'),
            'qualification_type_id' => $qualification->qualification_type_id,
            'subject_results' => [],
        ];
    }

    private function makeLevel2Officer(): User
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

    private function makeLevel1Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');
        $user->givePermissionTo(['verification.pool.view']);

        return $user;
    }

    public function test_save_without_correction_note_is_rejected_when_fields_change(): void
    {
        $qualification = $this->makeQualification($this->makeApplication());
        $l2 = $this->makeLevel2Officer();

        $payload = $this->basePayload($qualification);
        $payload['qualification_holder_name'] = 'Jane D. Corrected';

        $this->actingAs($l2)
            ->put(route('admin.verification.qualifications.update', $qualification), $payload)
            ->assertSessionHasErrors(['correction_note']);
    }

    public function test_save_with_correction_note_succeeds_and_records_audit_note(): void
    {
        $qualification = $this->makeQualification($this->makeApplication());
        $l2 = $this->makeLevel2Officer();

        $payload = $this->basePayload($qualification);
        $payload['qualification_holder_name'] = 'Jane D. Corrected';
        $payload['correction_note'] = 'Fixed holder name to match certificate.';

        $this->actingAs($l2)
            ->put(route('admin.verification.qualifications.update', $qualification), $payload)
            ->assertRedirect(route('admin.verification.qualifications.show', $qualification));

        $audit = AuditLog::query()
            ->where('event_type', 'verification.qualification_corrected')
            ->where('entity_id', $qualification->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('Fixed holder name to match certificate.', $audit->metadata['correction_note'] ?? null);
    }

    public function test_save_without_changes_redirects_with_info_and_without_note(): void
    {
        $qualification = $this->makeQualification($this->makeApplication());
        $l2 = $this->makeLevel2Officer();

        $this->actingAs($l2)
            ->put(route('admin.verification.qualifications.update', $qualification), $this->basePayload($qualification))
            ->assertRedirect(route('admin.verification.qualifications.edit', $qualification))
            ->assertSessionHas('info', 'No changes to save.');
    }

    public function test_level2_edit_page_includes_decision_permissions_when_eligible(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeApplication(), [
            'level2_review_owner_id' => $l2->id,
        ]);

        $this->actingAs($l2)
            ->get(route('admin.verification.qualifications.edit', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.approve', true)
                ->where('can.reject', true)
                ->where('qualification.verification_state', VerificationState::UnderLevel2Review->value));
    }

    public function test_level1_edit_page_does_not_include_level2_decision_permissions(): void
    {
        $l1 = $this->makeLevel1Officer();
        $qualification = $this->makeQualification($this->makeApplication(), [
            'verification_state' => VerificationState::UnderLevel1Review,
            'assigned_verifier_id' => $l1->id,
        ]);

        $this->actingAs($l1)
            ->get(route('admin.verification.qualifications.edit', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.approve', false)
                ->where('can.reject', false));
    }

    public function test_level2_edit_page_excludes_decision_permissions_when_not_in_reviewable_state(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeApplication(), [
            'verification_state' => VerificationState::ApprovedForCertificate,
        ]);

        $this->actingAs($l2)
            ->get(route('admin.verification.qualifications.edit', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.approve', true)
                ->where('can.reject', true)
                ->where('qualification.verification_state', VerificationState::ApprovedForCertificate->value));
    }

    public function test_approve_from_edit_page_uses_same_route_as_review_page(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeApplication(), [
            'level2_review_owner_id' => $l2->id,
        ]);

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.approve', $qualification), [
                'comment' => 'Looks good.',
                'issue_certificate' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $qualification->refresh();
        $this->assertSame(VerificationState::ApprovedForCertificate, $qualification->verification_state);
    }
}
