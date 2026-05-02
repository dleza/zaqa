<?php

namespace Tests\Feature;

use App\Domain\Fees\QualificationFeeResolver;
use App\Domain\Payments\ApplicationPaymentSatisfaction;
use App\Domain\Payments\InvoiceService;
use App\Enums\ApplicationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
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

class SupplementaryInvoiceAmendmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
    }

    public function test_paid_primary_invoice_unchanged_and_supplementary_created_for_fee_increase(): void
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $typeL6 = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $typeL7 = QualificationType::query()->where('zqf_level_code', 'L7')->firstOrFail();

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-SUP-'.rand(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $typeL6->zqf_level_code,
            'qualification_type_id' => $typeL6->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $invoiceService = app(InvoiceService::class);
        $invoiceService->ensureInvoice($application->fresh()->load('qualifications'), $applicant);

        $primary = Invoice::query()->where('application_id', $application->id)->whereNull('supplementary_of_invoice_id')->firstOrFail();
        $primaryAmountBefore = (int) $primary->amount_cents;
        $this->assertGreaterThan(0, $primaryAmountBefore);

        $primary->forceFill([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ])->save();

        Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $primary->id,
            'method' => PaymentMethod::MobileMoney,
            'status' => PaymentStatus::Confirmed,
            'currency' => $primary->currency,
            'amount_cents' => $primaryAmountBefore,
            'provider' => 'test',
            'confirmed_at' => now(),
            'last_status_at' => now(),
        ]);

        $qualification->forceFill([
            'qualification_type_id' => $typeL7->id,
            'qualification_type' => $typeL7->zqf_level_code,
        ])->save();

        $invoiceService->ensureInvoice($application->fresh()->load('qualifications'), $applicant);

        $primary->refresh();
        $this->assertSame(InvoiceStatus::Paid, $primary->status);
        $this->assertSame($primaryAmountBefore, (int) $primary->amount_cents);

        $qualification->refresh();
        $application->refresh()->load('qualifications');
        $required = app(QualificationFeeResolver::class)->totalVerificationFeesCents($application);
        $this->assertSame(50000, $required);

        $supplementary = Invoice::query()
            ->where('application_id', $application->id)
            ->whereNotNull('supplementary_of_invoice_id')
            ->where('status', InvoiceStatus::Issued)
            ->firstOrFail();

        $this->assertSame($primary->id, (int) $supplementary->supplementary_of_invoice_id);
        $this->assertSame((int) ($required - $primaryAmountBefore), (int) $supplementary->amount_cents);
        $this->assertStringContainsStringIgnoringCase('supplementary', (string) $supplementary->fee_label_snapshot);
    }

    public function test_finalize_amendment_blocked_until_supplementary_paid(): void
    {
        $application = $this->applicationWithPaidPrimaryAndSupplementaryDue();

        $qualification = Qualification::query()->where('application_id', $application->id)->firstOrFail();
        $qualification->forceFill([
            'verification_state' => VerificationState::ReturnedToApplicant,
        ])->save();

        $applicant = User::query()->findOrFail($application->applicant_user_id);

        $this->actingAs($applicant)
            ->post(route('applicant.applications.qualifications.finalize_amendment', [
                'application' => $application->id,
                'qualification' => $qualification->id,
            ]))
            ->assertSessionHasErrors('payment');

        $supplementary = Invoice::query()
            ->where('application_id', $application->id)
            ->whereNotNull('supplementary_of_invoice_id')
            ->where('status', InvoiceStatus::Issued)
            ->firstOrFail();

        Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $supplementary->id,
            'method' => PaymentMethod::MobileMoney,
            'status' => PaymentStatus::Confirmed,
            'currency' => $supplementary->currency,
            'amount_cents' => $supplementary->amount_cents,
            'provider' => 'test',
            'confirmed_at' => now(),
            'last_status_at' => now(),
        ]);

        $this->assertTrue(app(ApplicationPaymentSatisfaction::class)->isSatisfied($application->fresh()->load('qualifications', 'payments')));

        $this->actingAs($applicant)
            ->post(route('applicant.applications.qualifications.finalize_amendment', [
                'application' => $application->id,
                'qualification' => $qualification->id,
            ]))
            ->assertSessionHas('success');
    }

    public function test_fee_decrease_does_not_change_paid_primary_and_allows_satisfaction(): void
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $typeL6 = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $typeL7 = QualificationType::query()->where('zqf_level_code', 'L7')->firstOrFail();

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-OP-'.rand(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Degree',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $typeL7->zqf_level_code,
            'qualification_type_id' => $typeL7->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $invoiceService = app(InvoiceService::class);
        $invoiceService->ensureInvoice($application->fresh()->load('qualifications'), $applicant);

        $primary = Invoice::query()->where('application_id', $application->id)->whereNull('supplementary_of_invoice_id')->firstOrFail();
        $paidAmount = (int) $primary->amount_cents;

        $primary->forceFill([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ])->save();

        Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $primary->id,
            'method' => PaymentMethod::MobileMoney,
            'status' => PaymentStatus::Confirmed,
            'currency' => $primary->currency,
            'amount_cents' => $paidAmount,
            'provider' => 'test',
            'confirmed_at' => now(),
            'last_status_at' => now(),
        ]);

        $qualification->forceFill([
            'qualification_type_id' => $typeL6->id,
            'qualification_type' => $typeL6->zqf_level_code,
        ])->save();

        $invoiceService->ensureInvoice($application->fresh()->load('qualifications'), $applicant);

        $primary->refresh();
        $this->assertSame($paidAmount, (int) $primary->amount_cents);
        $this->assertSame(InvoiceStatus::Paid, $primary->status);

        $application->refresh();
        $meta = (array) ($application->metadata ?? []);
        $this->assertArrayHasKey('fee_amendment_overpayment_notice', $meta);
        $this->assertStringContainsStringIgnoringCase('Finance', (string) $meta['fee_amendment_overpayment_notice']);

        $this->assertTrue(app(ApplicationPaymentSatisfaction::class)->isSatisfied($application->fresh()->load('qualifications', 'payments')));
    }

    private function applicationWithPaidPrimaryAndSupplementaryDue(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $typeL6 = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $typeL7 = QualificationType::query()->where('zqf_level_code', 'L7')->firstOrFail();

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-FIN-'.rand(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $typeL6->zqf_level_code,
            'qualification_type_id' => $typeL6->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $invoiceService = app(InvoiceService::class);
        $invoiceService->ensureInvoice($application->fresh()->load('qualifications'), $applicant);

        $primary = Invoice::query()->where('application_id', $application->id)->whereNull('supplementary_of_invoice_id')->firstOrFail();
        $amt = (int) $primary->amount_cents;

        $primary->forceFill([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ])->save();

        Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $primary->id,
            'method' => PaymentMethod::MobileMoney,
            'status' => PaymentStatus::Confirmed,
            'currency' => $primary->currency,
            'amount_cents' => $amt,
            'provider' => 'test',
            'confirmed_at' => now(),
            'last_status_at' => now(),
        ]);

        $qualification->forceFill([
            'qualification_type_id' => $typeL7->id,
            'qualification_type' => $typeL7->zqf_level_code,
        ])->save();

        $invoiceService->ensureInvoice($application->fresh()->load('qualifications'), $applicant);

        return $application->fresh();
    }
}
