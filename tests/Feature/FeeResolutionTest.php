<?php

namespace Tests\Feature;

use App\Domain\Fees\QualificationFeeResolver;
use App\Domain\Payments\InvoiceService;
use App\Models\Application;
use App\Models\BillingCategory;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
    }

    public function test_fee_resolver_returns_local_and_foreign_fees(): void
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        /** @var QualificationFeeResolver $resolver */
        $resolver = $this->app->make(QualificationFeeResolver::class);

        $local = $resolver->resolve($type->id, false, now());
        $this->assertSame('ZMW', $local['currency']);
        $this->assertSame(20000, $local['fee_cents']); // Local Certificates & Diplomas
        $this->assertSame(14, $local['processing_days']);

        $foreign = $resolver->resolve($type->id, true, now());
        $this->assertSame(120000, $foreign['fee_cents']); // Foreign fee path
        $this->assertSame(60, $foreign['processing_days']);
    }

    public function test_invoice_snapshots_fee_and_remains_historically_correct_after_fee_change(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);

        $application = Application::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'application_number' => 'ZAQA-TEST-INV-001',
            'applicant_user_id' => $user->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => [],
        ]);

        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'transcript_required' => false,
        ]);

        /** @var InvoiceService $invoices */
        $invoices = $this->app->make(InvoiceService::class);
        $invoice = $invoices->ensureInvoice($application, $user);

        $this->assertSame(20000, $invoice->amount_cents);
        $this->assertNotNull($invoice->fee_structure_id);

        $originalFeeStructureId = $invoice->fee_structure_id;

        // Change fees by creating a newer effective fee structure for the same category.
        $category = BillingCategory::query()->where('code', 'LOCAL_CERTS_DIPLOMAS')->firstOrFail();
        FeeStructure::query()->create([
            'billing_category_id' => $category->id,
            'local_fee_cents' => 25000,
            'foreign_fee_cents' => 130000,
            'currency' => 'ZMW',
            'effective_from' => now()->addSecond(),
            'effective_to' => null,
            'is_active' => true,
            'change_reason' => 'Test fee increase',
        ]);

        $invoice->refresh();
        $this->assertSame(20000, $invoice->amount_cents);
        $this->assertSame($originalFeeStructureId, $invoice->fee_structure_id);

        $reloaded = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame(20000, $reloaded->amount_cents);
        $this->assertSame($originalFeeStructureId, $reloaded->fee_structure_id);
    }
}

