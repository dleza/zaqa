<?php

namespace Tests\Feature;

use App\Domain\Applications\ReferenceNumberService;
use App\Domain\Verification\ApplicationsPoolService;
use App\Domain\Verification\QualificationsPoolService;
use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferenceNumberGenerationTest extends TestCase
{
    use RefreshDatabase;

    private ReferenceNumberService $references;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);

        $this->references = app(ReferenceNumberService::class);
    }

    public function test_new_application_reference_is_numeric_only(): void
    {
        Carbon::setTestNow('2026-03-15 10:00:00');

        $reference = $this->references->generateApplicationNumber();

        $this->assertSame('2026-000001', $reference);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{6}$/', $reference);
        $this->assertStringNotContainsString('ZAQA', $reference);
    }

    public function test_new_application_references_increment_within_year(): void
    {
        Carbon::setTestNow('2026-03-15 10:00:00');

        $this->assertSame('2026-000001', $this->references->generateApplicationNumber());
        $this->assertSame('2026-000002', $this->references->generateApplicationNumber());
    }

    public function test_application_sequence_resets_each_year(): void
    {
        Carbon::setTestNow('2026-12-31 23:59:00');
        $this->assertSame('2026-000001', $this->references->generateApplicationNumber());

        Carbon::setTestNow('2027-01-01 00:01:00');
        $this->assertSame('2027-000001', $this->references->generateApplicationNumber());
    }

    public function test_new_qualification_reference_extends_parent_application_reference(): void
    {
        $application = $this->makeApplication('2026-000247');

        $reference = $this->references->generateQualificationVerificationReference($application);

        $this->assertSame('2026-000247-01', $reference);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{6}-\d{2}$/', $reference);
        $this->assertStringNotContainsString('ZAQA', $reference);
    }

    public function test_multiple_qualifications_increment_per_application(): void
    {
        $application = $this->makeApplication('2026-000001');

        Qualification::query()->create($this->qualificationAttributes($application->id, [
            'verification_reference_number' => '2026-000001-01',
            'awarding_institution_name' => 'School A',
        ]));

        $second = $this->references->generateQualificationVerificationReference($application);
        $this->assertSame('2026-000001-02', $second);
        Qualification::query()->create($this->qualificationAttributes($application->id, [
            'verification_reference_number' => $second,
            'awarding_institution_name' => 'School B',
        ]));

        $this->assertSame('2026-000001-03', $this->references->generateQualificationVerificationReference($application));
    }

    public function test_qualification_reference_is_not_reused_after_deletion(): void
    {
        $application = $this->makeApplication('2026-000010');

        $first = Qualification::query()->create($this->qualificationAttributes($application->id, [
            'verification_reference_number' => '2026-000010-01',
            'awarding_institution_name' => 'School A',
        ]));
        Qualification::query()->create($this->qualificationAttributes($application->id, [
            'verification_reference_number' => '2026-000010-02',
            'awarding_institution_name' => 'School B',
        ]));

        $first->delete();

        $this->assertSame('2026-000010-03', $this->references->generateQualificationVerificationReference($application));
    }

    public function test_existing_old_references_remain_unchanged_when_assigning_missing_numbers(): void
    {
        $application = $this->makeApplication('ZAQA-2026-LEGACYAPP1');
        $legacyReference = 'ZAQA-Q-2026-LEGACYQUAL1';

        $qualification = Qualification::query()->create($this->qualificationAttributes($application->id, [
            'verification_reference_number' => $legacyReference,
            'awarding_institution_name' => 'Legacy School',
            'title_of_qualification' => 'Legacy Diploma',
            'qualification_type' => 'L6',
            'qualification_type_id' => $this->diplomaTypeId(),
        ]));

        $this->references->assignQualificationVerificationReferences($application);

        $qualification->refresh();
        $application->refresh();

        $this->assertSame('ZAQA-2026-LEGACYAPP1', $application->application_number);
        $this->assertSame($legacyReference, $qualification->verification_reference_number);
    }

    public function test_new_qualification_on_old_application_uses_legacy_reference_format(): void
    {
        $application = $this->makeApplication('ZAQA-2026-LEGACYAPP2');

        $reference = $this->references->generateQualificationVerificationReference($application);

        $this->assertStringStartsWith('ZAQA-Q-', $reference);
        $this->assertMatchesRegularExpression('/^ZAQA-Q-\d{4}-[A-Z0-9]{10}$/', $reference);
    }

    public function test_draft_creation_assigns_numeric_application_reference(): void
    {
        Carbon::setTestNow('2026-06-16 09:00:00');
        $user = $this->makeApplicantUser();
        $this->actingAs($user);

        $this->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
        ])->assertRedirect();

        $application = Application::query()->firstOrFail();

        $this->assertSame('2026-000001', $application->application_number);
    }

    public function test_search_finds_old_and_new_application_references(): void
    {
        $old = $this->makeSubmittedApplication('ZAQA-2026-OLDFINDME', 'ZAQA-Q-2026-OLDFINDME');
        $new = $this->makeSubmittedApplication('2026-000888', '2026-000888-01');

        $service = app(ApplicationsPoolService::class);

        $oldResults = $service->pool(new Request(['q' => 'ZAQA-2026-OLDFINDME']))->getCollection();
        $newResults = $service->pool(new Request(['q' => '2026-000888']))->getCollection();

        $this->assertTrue($oldResults->contains(fn (Application $app) => $app->id === $old->id));
        $this->assertTrue($newResults->contains(fn (Application $app) => $app->id === $new->id));
    }

    public function test_search_finds_old_and_new_qualification_references(): void
    {
        $old = $this->makeSubmittedApplication('ZAQA-2026-QUALFIND1', 'ZAQA-Q-2026-QUALFIND1');
        $new = $this->makeSubmittedApplication('2026-000777', '2026-000777-02');

        $service = app(QualificationsPoolService::class);

        $oldResults = $service->pool(new Request(['q' => 'ZAQA-Q-2026-QUALFIND1']))->getCollection();
        $newResults = $service->pool(new Request(['q' => '2026-000777-02']))->getCollection();

        $this->assertTrue($oldResults->contains(fn (Qualification $qualification) => $qualification->application_id === $old->id));
        $this->assertTrue($newResults->contains(fn (Qualification $qualification) => $qualification->application_id === $new->id));
    }

    public function test_concurrent_application_generation_produces_unique_references(): void
    {
        Carbon::setTestNow('2026-06-16 10:00:00');

        $references = collect(range(1, 5))
            ->map(fn () => $this->references->generateApplicationNumber())
            ->all();

        $this->assertSame([
            '2026-000001',
            '2026-000002',
            '2026-000003',
            '2026-000004',
            '2026-000005',
        ], $references);
        $this->assertCount(5, array_unique($references));
    }

    private function makeApplicantUser(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);
        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'surname' => 'Doe',
            'gender' => 'male',
            'nrc_number' => '111111/11/1',
            'identity_type' => 'nrc',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'identity_document_uploaded_at' => now(),
        ]);

        return $user;
    }

    private function makeApplication(string $applicationNumber): Application
    {
        $user = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => $applicationNumber,
            'applicant_user_id' => $user->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'certificate',
            'current_status' => ApplicationStatus::Draft,
            'is_foreign' => false,
        ]);
    }

    private function makeSubmittedApplication(string $applicationNumber, string $qualificationReference): Application
    {
        $application = $this->makeApplication($applicationNumber);
        $application->forceFill([
            'current_status' => ApplicationStatus::Submitted,
            'submitted_at' => now(),
            'paid_at' => now(),
        ])->save();

        Qualification::query()->create($this->qualificationAttributes($application->id, [
            'verification_reference_number' => $qualificationReference,
        ]));

        return $application->fresh(['qualifications']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function qualificationAttributes(int $applicationId, array $overrides = []): array
    {
        return array_merge([
            'application_id' => $applicationId,
            'awarding_institution_name' => 'Test School',
            'qualification_holder_name' => 'Jane Applicant',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Test Qualification',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L2B',
            'qualification_type_id' => $this->schoolTypeId(),
            'is_foreign_qualification' => false,
        ], $overrides);
    }

    private function schoolTypeId(): int
    {
        return (int) QualificationType::query()->where('zqf_level_code', 'L2B')->value('id');
    }

    private function diplomaTypeId(): int
    {
        return (int) QualificationType::query()->where('zqf_level_code', 'L6')->value('id');
    }
}
