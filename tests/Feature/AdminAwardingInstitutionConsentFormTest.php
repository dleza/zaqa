<?php

namespace Tests\Feature;

use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminAwardingInstitutionConsentFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_upload_consent_form_on_create_awarding_institution(): void
    {
        Storage::fake('local');

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $country = Country::query()->where('iso_code', 'ZAF')->first()
            ?? Country::query()->create(['iso_code' => 'ZAF', 'name' => 'South Africa', 'is_active' => true, 'sort_order' => 0]);

        $response = $this->post('/admin/settings/awarding-institutions', [
            'country_id' => $country->id,
            'name' => 'Test Foreign University',
            'is_active' => true,
            'sort_order' => 0,
            'consent_form' => UploadedFile::fake()->create('consent-template.pdf', 50, 'application/pdf'),
        ]);

        $response->assertRedirect('/admin/settings/awarding-institutions');

        $inst = AwardingInstitution::query()->where('name', 'Test Foreign University')->firstOrFail();
        $this->assertNotNull($inst->consent_form_path);

        $this->assertTrue(Storage::disk('local')->exists($inst->consent_form_path));
    }

    public function test_admin_can_replace_consent_form_on_edit_awarding_institution(): void
    {
        Storage::fake('local');

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        $country = Country::query()->where('iso_code', 'ZAF')->first()
            ?? Country::query()->create(['iso_code' => 'ZAF', 'name' => 'South Africa', 'is_active' => true, 'sort_order' => 0]);

        $inst = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Replace Me University',
            'is_active' => true,
            'sort_order' => 0,
            'consent_form_path' => 'private/awarding-institutions/test/consent-form/old.pdf',
        ]);
        Storage::disk('local')->put($inst->consent_form_path, 'old');

        $response = $this->put("/admin/settings/awarding-institutions/{$inst->id}", [
            'country_id' => $country->id,
            'name' => $inst->name,
            'is_active' => true,
            'sort_order' => 0,
            'consent_form' => UploadedFile::fake()->create('new-template.pdf', 60, 'application/pdf'),
        ]);

        $response->assertRedirect();

        $inst->refresh();
        $this->assertNotNull($inst->consent_form_path);

        $this->assertFalse(Storage::disk('local')->exists('private/awarding-institutions/test/consent-form/old.pdf'));
        $this->assertTrue(Storage::disk('local')->exists($inst->consent_form_path));
    }
}

