<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Invoice;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplicantPaymentStepAutoInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_visiting_payment_step_auto_prepares_invoice_when_ready(): void
    {
        Storage::fake('local');

        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'surname' => 'Doe',
            'gender' => 'male',
            'nrc_number' => '111111/11/1',
            'passport_number' => null,
            'identity_type' => 'nrc',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
        ]);

        $zambia = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $institution = AwardingInstitution::query()->create([
            'country_id' => $zambia->id,
            'name' => 'Test Institution',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-TEST-PAYSTEP-1',
            'applicant_user_id' => $user->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => 'verification',
            'qualification_category' => 'test',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => [
                'submitting_for' => 'self',
                'wizard_declarations' => [
                    'terms_accepted_at' => now()->toIso8601String(),
                    'information_confirmed_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $type = QualificationType::query()
            ->where('zqf_level_code', 'L6')
            ->firstOrFail();

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'John Doe',
            'country_id' => $zambia->id,
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-001',
            'student_number' => null,
            'examination_number' => null,
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => (string) ($type->name ?? 'Qualification'),
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $this->actingAs($user);

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'certificate_copy',
            'qualification_id' => $qualification->id,
            'file' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'nrc_copy',
            'file' => UploadedFile::fake()->image('nrc.png')->size(200),
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/consent/accept", [
            'agreed_by_name' => $user->name,
        ])->assertRedirect();

        $this->assertDatabaseCount('invoices', 0);

        $res = $this->get(route('applicant.applications.edit', ['application' => $application->id, 'step' => 'payment']));
        $res->assertOk();

        $this->assertDatabaseCount('invoices', 1);

        $invoice = Invoice::query()->where('application_id', $application->id)->firstOrFail();

        $res->assertInertia(fn (Assert $page) => $page
            ->component('Applicant/Applications/Edit', false)
            ->where('application.invoice.id', $invoice->id)
            ->where('application.invoice.invoice_number', $invoice->invoice_number)
        );

        // Refreshing the payment step should not create a duplicate invoice.
        $this->get(route('applicant.applications.edit', ['application' => $application->id, 'step' => 'payment']))
            ->assertOk();

        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_payment_step_does_not_prepare_invoice_when_application_not_ready(): void
    {
        Storage::fake('local');

        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'surname' => 'Doe',
            'gender' => 'male',
            'nrc_number' => '111111/11/1',
            'passport_number' => null,
            'identity_type' => 'nrc',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
        ]);

        $zambia = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $institution = AwardingInstitution::query()->create([
            'country_id' => $zambia->id,
            'name' => 'Test Institution',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-TEST-PAYSTEP-2',
            'applicant_user_id' => $user->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => 'verification',
            'qualification_category' => 'test',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => [
                'submitting_for' => 'self',
                // Missing wizard declarations + missing documents.
            ],
        ]);

        $type = QualificationType::query()
            ->where('zqf_level_code', 'L6')
            ->firstOrFail();

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'John Doe',
            'country_id' => $zambia->id,
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-002',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => (string) ($type->name ?? 'Qualification'),
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $this->actingAs($user);

        $this->get(route('applicant.applications.edit', ['application' => $application->id, 'step' => 'payment']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/Applications/Edit', false)
                ->where('application.invoice', null)
            );

        $this->assertDatabaseCount('invoices', 0);
    }
}

