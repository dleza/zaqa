<?php

namespace Tests\Feature;

use App\Domain\Finance\PaymentReceiptPdfService;
use App\Domain\Payments\InvoiceService;
use App\Domain\Settings\DocumentSignatureService;
use App\Enums\ApplicantType;
use App\Enums\DocumentSignatureType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\DocumentSignatureSetting;
use App\Models\Payment;
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

class DocumentSignatureSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
    }

    public function test_authorized_admin_can_view_signature_settings(): void
    {
        $admin = $this->adminWithPermissions(['settings.document_signatures.view']);

        $this->actingAs($admin)->get(route('admin.settings.document_signatures.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Settings/DocumentSignatures/Index'));
    }

    public function test_unauthorized_user_cannot_view_signature_settings(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view']);

        $this->actingAs($user)->get(route('admin.settings.document_signatures.index'))->assertForbidden();
    }

    public function test_authorized_admin_can_upload_receipt_signature_png(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.document_signatures.view',
            'settings.document_signatures.manage',
        ]);

        $file = $this->pngUpload();

        $this->actingAs($admin)->post(route('admin.settings.document_signatures.store'), [
            'type' => DocumentSignatureType::Receipt->value,
            'display_name' => 'Finance Director',
            'file' => $file,
        ])->assertRedirect();

        $this->assertDatabaseHas('document_signature_settings', [
            'type' => DocumentSignatureType::Receipt->value,
            'is_active' => true,
            'display_name' => 'Finance Director',
        ]);
    }

    public function test_authorized_admin_can_upload_certificate_signature_png(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.document_signatures.view',
            'settings.document_signatures.manage',
        ]);

        $this->actingAs($admin)->post(route('admin.settings.document_signatures.store'), [
            'type' => DocumentSignatureType::Certificate->value,
            'file' => $this->pngUpload(),
        ])->assertRedirect();

        $this->assertDatabaseHas('document_signature_settings', [
            'type' => DocumentSignatureType::Certificate->value,
            'is_active' => true,
        ]);
    }

    public function test_non_png_upload_is_rejected(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.document_signatures.view',
            'settings.document_signatures.manage',
        ]);

        $this->actingAs($admin)->post(route('admin.settings.document_signatures.store'), [
            'type' => DocumentSignatureType::Receipt->value,
            'file' => UploadedFile::fake()->create('signature.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('file');
    }

    public function test_oversized_png_is_rejected(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.document_signatures.view',
            'settings.document_signatures.manage',
        ]);

        $this->actingAs($admin)->post(route('admin.settings.document_signatures.store'), [
            'type' => DocumentSignatureType::Receipt->value,
            'file' => UploadedFile::fake()->create('signature.png', 3000, 'image/png'),
        ])->assertSessionHasErrors('file');
    }

    public function test_replacing_signature_deactivates_previous_active_signature(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.document_signatures.view',
            'settings.document_signatures.manage',
        ]);
        $service = app(DocumentSignatureService::class);

        $first = $service->storeUpload(DocumentSignatureType::Receipt, $this->pngUpload(), $admin, 'First');
        $second = $service->storeUpload(DocumentSignatureType::Receipt, $this->pngUpload(), $admin, 'Second');

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertSame(1, DocumentSignatureSetting::query()->where('type', DocumentSignatureType::Receipt)->where('is_active', true)->count());
    }

    public function test_receipt_pdf_uses_active_receipt_signature(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.document_signatures.view',
            'settings.document_signatures.manage',
        ]);

        app(DocumentSignatureService::class)->storeUpload(
            DocumentSignatureType::Receipt,
            $this->pngUpload(),
            $admin,
        );

        [$payment] = $this->confirmedPaymentPair();

        $data = app(PaymentReceiptPdfService::class)->buildViewData($payment->fresh());

        $this->assertNotNull($data['signature_data_uri']);
        $this->assertStringStartsWith('data:image/png;base64,', $data['signature_data_uri']);
    }

    /**
     * @return array{0: Payment}
     */
    private function confirmedPaymentPair(): array
    {
        $applicant = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
            'email' => 'sig-receipt-'.Str::lower((string) Str::ulid()).'@example.test',
        ]);

        ApplicantProfile::query()->create([
            'user_id' => $applicant->id,
            'first_name' => 'Sig',
            'surname' => 'Test',
            'email' => $applicant->email,
            'phone_primary' => '0977993537',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-SIG-'.random_int(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'pending_payment',
            'is_foreign' => false,
            'metadata' => [],
        ]);

        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Sig Test',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => "Bachelor's Degree Certificate",
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'certificate_number' => 'CERT-SIG-001',
        ]);

        $invoice = app(InvoiceService::class)->ensureInvoice($application->fresh('qualifications'), $applicant);

        $payment = Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::MobileMoney,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => (int) $invoice->amount_cents,
            'provider' => 'cgrate',
            'provider_reference' => 'MM-SIG',
            'initiated_at' => now()->subMinutes(5),
            'confirmed_at' => now(),
            'last_status_at' => now()->subMinutes(5),
        ]);

        return [$payment->fresh(['application', 'invoice'])];
    }

    public function test_certificate_signature_service_returns_data_uri_when_configured(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.document_signatures.view',
            'settings.document_signatures.manage',
        ]);

        app(DocumentSignatureService::class)->storeUpload(
            DocumentSignatureType::Certificate,
            $this->pngUpload(),
            $admin,
        );

        $uri = app(DocumentSignatureService::class)->dataUriForType(DocumentSignatureType::Certificate);

        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/png;base64,', $uri);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function adminWithPermissions(array $permissions): User
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(array_merge(['dashboard.view'], $permissions));

        return $admin;
    }

    private function pngUpload(): UploadedFile
    {
        $path = resource_path('images/zaqa_logo_clean.png');

        return UploadedFile::fake()->createWithContent('signature.png', (string) file_get_contents($path));
    }
}
