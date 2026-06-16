<?php

namespace Tests\Feature;

use App\Domain\Applications\ApplicationSubmissionService;
use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\ApplicantProfile;
use App\Models\BillingCategory;
use App\Models\Country;
use App\Models\Payment;
use App\Models\QualificationType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HolderIdentityFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $cat = BillingCategory::query()->create([
            'code' => 'TEST_CAT',
            'name' => 'Test category',
            'local_processing_days' => 14,
            'foreign_processing_days' => 60,
            'is_active' => true,
        ]);

        QualificationType::query()->create([
            'zqf_level_code' => 'T1',
            'level_label' => 'Test level',
            'name' => 'Test qualification type',
            'short_name' => 'Test',
            'description' => null,
            'billing_category_id' => $cat->id,
            'is_active' => true,
            'sort_order' => 1,
            'requires_subject_results' => false,
        ]);
    }

    public function test_self_application_submission_is_blocked_if_holder_identity_missing_on_qualification(): void
    {
        $user = \App\Models\User::factory()->create([
            'applicant_type' => ApplicantType::Individual,
            'email' => 'martin@example.test',
            'phone_primary' => '260955000111',
            'is_active' => true,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
        ApplicantProfile::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Martin',
            'middle_name' => null,
            'surname' => 'Mwale',
            // identity intentionally missing to simulate incomplete profile
            'nrc_number' => null,
            'passport_number' => null,
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
        ]);

        $this->actingAs($user);

        $zambia = Country::query()->firstOrCreate(['iso_code' => 'ZMB'], ['name' => 'Zambia']);
        $qt = QualificationType::query()->first();
        $this->assertNotNull($qt);

        $application = Application::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'application_number' => 'ZAQA-TEST-0001',
            'applicant_user_id' => $user->id,
            'applicant_type' => $user->applicant_type,
            'service_type' => 'verification',
            'qualification_category' => 'test',
            'current_status' => ApplicationStatus::Draft,
            'is_foreign' => false,
            'metadata' => [
                'submitting_for' => 'self',
                'verification_subject' => [
                    'full_name' => 'Martin Mwale',
                    // identity intentionally missing
                ],
            ],
        ]);

        // Save qualification details WITHOUT identity fields; the service must not silently allow final submission.
        $this->put("/applicant/applications/{$application->id}/qualification/details", [
            'country_id' => $zambia->id,
            'awarding_institution_id' => 'other',
            'awarding_institution_name_other' => 'Test Institution',
            'certificate_number' => 'CERT-1',
            'student_number' => '',
            'examination_number' => '',
            'title_of_qualification' => 'Test Qualification',
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type_id' => $qt->id,
            'notes' => null,
        ])->assertSessionHasNoErrors();

        // Ensure payment is confirmed so the blocker is ONLY holder identity.
        Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => null,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => 1000,
            'provider' => 'test',
            'provider_reference' => 'TEST-REF',
            'initiated_at' => now(),
            'confirmed_at' => now(),
        ]);

        $submission = app(ApplicationSubmissionService::class);

        $this->expectException(ValidationException::class);
        $submission->submit($application, $user);
    }

    public function test_pool_search_can_match_holder_identity_fields(): void
    {
        $user = \App\Models\User::factory()->create([
            'applicant_type' => ApplicantType::Individual,
            'email' => 'jane@example.test',
            'phone_primary' => '260955000222',
            'is_active' => true,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
        ApplicantProfile::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Jane',
            'middle_name' => null,
            'surname' => 'Doe',
            'nrc_number' => '000000/00/1',
            'passport_number' => null,
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
        ]);

        $application = Application::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'application_number' => 'ZAQA-TEST-0002',
            'applicant_user_id' => $user->id,
            'applicant_type' => $user->applicant_type,
            'service_type' => 'verification',
            'qualification_category' => 'test',
            'current_status' => ApplicationStatus::Submitted,
            'is_foreign' => false,
            'metadata' => [
                'verification_subject' => [
                    'full_name' => 'Other Holder',
                    'nrc_number' => '123456/12/1',
                ],
            ],
            'submitted_at' => now(),
        ]);

        // Create a qualification row to match against.
        $application->qualification()->create([
            'awarding_institution_name' => 'Inst',
            'qualification_holder_name' => 'Other Holder',
            'country_id' => null,
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '123456/12/1',
            'certificate_number' => 'CERT-2',
            'student_number' => null,
            'examination_number' => null,
            'title_of_qualification' => 'Test',
            'award_date' => now()->subYears(2)->toDateString(),
            'qualification_type' => 'ZQF',
            'qualification_type_id' => QualificationType::query()->value('id'),
            'transcript_required' => false,
        ]);

        // Create an admin who can view verification pool.
        $admin = \App\Models\User::factory()->create(['applicant_type' => null, 'is_active' => true]);
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $this->get('/admin/verification/pool?q=123456')
            ->assertOk()
            ->assertSee('ZAQA-TEST-0002');
    }
}

