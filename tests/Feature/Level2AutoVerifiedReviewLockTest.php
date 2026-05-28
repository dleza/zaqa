<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\BillingCategory;
use App\Models\Country;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use App\Domain\Payments\ApplicationPaymentSatisfaction;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Level2AutoVerifiedReviewLockTest extends TestCase
{
    use RefreshDatabase;

    private function makeQualificationInAutoVerifiedState(User $applicant, int $instId, int $typeId, string $title = 'Diploma in Testing'): Qualification
    {
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'is_foreign' => false,
            'metadata' => ['verification_subject' => ['full_name' => 'John Doe']],
            'submitted_at' => now(),
        ]);

        return Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $instId,
            'awarding_institution_name' => 'Test University',
            'qualification_holder_name' => 'John Doe',
            'nrc_passport_number' => '111111/11/1',
            'student_number' => 'STU-001',
            'certificate_number' => null,
            'title_of_qualification' => $title,
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'qualification_type_id' => $typeId,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'auto_verification_confidence' => 80,
            'auto_verification_status' => 'matched',
            'verification_source' => 'internal_learner_record',
            'verified_qualification_title' => $title,
        ]);
    }

    public function test_level2_can_lock_and_other_level2_cannot_approve_without_lock(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $l2a = User::factory()->activated()->create(['applicant_type' => null]);
        $l2a->assignRole('Verification Officer Level 2');

        $l2b = User::factory()->activated()->create(['applicant_type' => null]);
        $l2b->assignRole('Verification Officer Level 2');

        $country = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $inst = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Test University',
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $billing = BillingCategory::query()->create([
            'name' => 'Test Billing',
            'code' => 'TEST_BILLING',
            'description' => null,
            'local_processing_days' => 10,
            'foreign_processing_days' => 20,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $type = QualificationType::query()->create([
            'name' => 'Test Type',
            'zqf_level_code' => 'L6',
            'level_label' => 'Level 6',
            'billing_category_id' => $billing->id,
            'is_active' => true,
            'requires_subject_results' => false,
            'sort_order' => 1,
        ]);

        $qualification = $this->makeQualificationInAutoVerifiedState($l2a, $inst->id, $type->id);

        $this->actingAs($l2a);
        $this->post("/admin/verification/qualifications/{$qualification->id}/level2-lock")
            ->assertSessionHasNoErrors();

        $qualification->refresh();
        $this->assertSame($l2a->id, (int) $qualification->level2_review_locked_by);

        $this->actingAs($l2b);
        $this->post("/admin/verification/qualifications/{$qualification->id}/approve", [
            'comment' => '',
            'issue_certificate' => false,
        ])->assertSessionHasErrors(['lock']);

        $qualification->refresh();
        $this->assertSame(VerificationState::AutoVerifiedPendingLevel2, $qualification->verification_state);
    }

    public function test_lock_expires_after_30_minutes_and_another_level2_can_take_over(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $l2a = User::factory()->activated()->create(['applicant_type' => null]);
        $l2a->assignRole('Verification Officer Level 2');

        $l2b = User::factory()->activated()->create(['applicant_type' => null]);
        $l2b->assignRole('Verification Officer Level 2');

        $country = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $inst = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Test University',
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $billing = BillingCategory::query()->create([
            'name' => 'Test Billing',
            'code' => 'TEST_BILLING',
            'description' => null,
            'local_processing_days' => 10,
            'foreign_processing_days' => 20,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $type = QualificationType::query()->create([
            'name' => 'Test Type',
            'zqf_level_code' => 'L6',
            'level_label' => 'Level 6',
            'billing_category_id' => $billing->id,
            'is_active' => true,
            'requires_subject_results' => false,
            'sort_order' => 1,
        ]);

        $qualification = $this->makeQualificationInAutoVerifiedState($l2a, $inst->id, $type->id);
        $qualification->forceFill([
            'level2_review_locked_by' => $l2a->id,
            'level2_review_locked_at' => now()->subMinutes(31),
        ])->save();

        $this->actingAs($l2b);
        $this->post("/admin/verification/qualifications/{$qualification->id}/level2-lock")
            ->assertSessionHasNoErrors();

        $qualification->refresh();
        $this->assertSame($l2b->id, (int) $qualification->level2_review_locked_by);
    }

    public function test_approve_and_issue_certificate_works_for_auto_verified_pending_level2_and_releases_lock(): void
    {
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $l2 = User::factory()->activated()->create(['applicant_type' => null]);
        $l2->assignRole('Verification Officer Level 2');

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $country = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $inst = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Test University',
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        $qualification = $this->makeQualificationInAutoVerifiedState($applicant, $inst->id, $type->id);
        $application = $qualification->application()->firstOrFail();

        Storage::fake('local');
        Mail::fake();

        $satisfaction = app(ApplicationPaymentSatisfaction::class);
        $required = $satisfaction->outstandingCents($application);
        $this->assertGreaterThan(0, $required);

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

        $this->actingAs($l2);
        $this->post("/admin/verification/qualifications/{$qualification->id}/level2-lock")
            ->assertSessionHasNoErrors();

        $this->post("/admin/verification/qualifications/{$qualification->id}/approve", [
            'comment' => '',
            'issue_certificate' => true,
        ])->assertSessionHasNoErrors();

        $qualification->refresh();
        $this->assertSame(VerificationState::CertificateIssued, $qualification->verification_state);
        $this->assertNull($qualification->level2_review_locked_by);
        $this->assertNull($qualification->level2_review_locked_at);
    }
}
