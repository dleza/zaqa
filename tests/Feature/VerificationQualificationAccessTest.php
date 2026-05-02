<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentType;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class VerificationQualificationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeSubmittedApplication(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
        ]);
    }

    public function test_level1_cannot_view_qualification_assigned_to_another_officer(): void
    {
        $application = $this->makeSubmittedApplication();

        $assignedOfficer = User::factory()->activated()->create(['applicant_type' => null]);
        $assignedOfficer->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $otherOfficer = User::factory()->activated()->create(['applicant_type' => null]);
        $otherOfficer->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $assignedOfficer->id,
        ]);

        $this->actingAs($otherOfficer)
            ->get(route('admin.verification.qualifications.show', ['qualification' => $qualification->id]))
            ->assertForbidden();
    }

    public function test_level1_can_view_own_assigned_qualification(): void
    {
        $application = $this->makeSubmittedApplication();

        $officer = User::factory()->activated()->create(['applicant_type' => null]);
        $officer->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $officer->id,
        ]);

        $this->actingAs($officer)
            ->get(route('admin.verification.qualifications.show', ['qualification' => $qualification->id]))
            ->assertOk();
    }

    public function test_level2_can_view_qualification_assigned_to_someone_else(): void
    {
        $application = $this->makeSubmittedApplication();

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1->givePermissionTo(['verification.level1.process', 'verification.pool.view']);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo([
            'verification.assign',
            'verification.pool.view',
            'verification.level2.review',
            'dashboard.view',
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $level1->id,
        ]);

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.show', ['qualification' => $qualification->id]))
            ->assertOk();
    }

    public function test_qualification_pool_for_level1_only_lists_assigned_tasks(): void
    {
        $application = $this->makeSubmittedApplication();

        $mine = User::factory()->activated()->create(['applicant_type' => null]);
        $mine->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $other = User::factory()->activated()->create(['applicant_type' => null]);

        $qMine = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'A',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma A',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $mine->id,
        ]);

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'B',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma B',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $other->id,
        ]);

        $page = $this->actingAs($mine)->get(route('admin.verification.pool.index'));

        $page->assertOk();
        $page->assertInertia(fn ($page) => $page
            ->has('qualifications.data', 1)
            ->where('qualifications.data.0.id', $qMine->id));
    }

    public function test_level1_cannot_open_parent_application_without_assigned_qualification(): void
    {
        $application = $this->makeSubmittedApplication();

        $officer = User::factory()->activated()->create(['applicant_type' => null]);
        $officer->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $other = User::factory()->activated()->create(['applicant_type' => null]);

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'B',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma B',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $other->id,
        ]);

        $this->actingAs($officer)
            ->get(route('admin.verification.applications.show', ['application' => $application->id]))
            ->assertForbidden();
    }

    public function test_level2_can_revoke_level1_assignment(): void
    {
        $application = $this->makeSubmittedApplication();

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo(['verification.assign', 'verification.pool.view', 'dashboard.view']);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $level1->id,
            'assigned_at' => now(),
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.revoke_assignment', ['qualification' => $qualification->id]), [
                'comment' => 'Needs different reviewer.',
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertNull($qualification->assigned_verifier_id);
        $this->assertSame(VerificationState::AwaitingAssignment, $qualification->verification_state);
    }

    public function test_level1_cannot_revoke_assignment(): void
    {
        $application = $this->makeSubmittedApplication();

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $other = User::factory()->activated()->create(['applicant_type' => null]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $other->id,
            'assigned_at' => now(),
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.revoke_assignment', ['qualification' => $qualification->id]))
            ->assertForbidden();
    }

    public function test_level1_complete_stores_optional_attachment(): void
    {
        $application = $this->makeSubmittedApplication();

        $officer = User::factory()->activated()->create(['applicant_type' => null]);
        $officer->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $officer->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $file = UploadedFile::fake()->create('l1-notes.pdf', 100, 'application/pdf');

        $this->actingAs($officer)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'findings' => 'All checks done; ready for Level 2.',
                'attachment' => $file,
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel2Review, $qualification->verification_state);

        $this->assertDatabaseHas('qualification_documents', [
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::Level1ReviewAttachment->value,
        ]);
    }
}
