<?php

namespace Tests\Feature;

use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\LearnerRecord;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAwardingInstitutionProfileDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_button_has_show_url_and_authorized_admin_can_view_institution_profile(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo('dashboard.view');
        $admin->givePermissionTo('settings.awarding_institutions.view');

        $country = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]);

        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Profile University',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->get('/admin/settings/awarding-institutions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/AwardingInstitutions/Index')
                ->has('institutions.data', 1)
                ->where('institutions.data.0.id', $institution->id)
                ->where('institutions.data.0.show_url', route('admin.settings.awarding_institutions.show', ['awardingInstitution' => $institution->id]))
            );

        $this->actingAs($admin)
            ->get("/admin/settings/awarding-institutions/{$institution->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/AwardingInstitutions/Show')
                ->where('institution.id', $institution->id)
                ->where('institution.name', 'Profile University')
                ->has('stats')
                ->has('links')
                ->has('recent_qualifications')
            );
    }

    public function test_deactivate_and_reactivate_affects_applicant_selection_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo('dashboard.view');
        $admin->givePermissionTo('settings.awarding_institutions.delete');
        $admin->givePermissionTo('settings.awarding_institutions.view');

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $country = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]);
        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Toggle University',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        LearnerRecord::query()->create([
            'awarding_institution_id' => $institution->id,
            'student_id' => 'STU-1',
            'student_id_normalized' => \App\Support\Normalization\LearnerRecordNormalizer::normalizeStudentId('STU-1'),
            'program_of_study' => 'Diploma in Toggling',
            'qualification_title_normalized' => \App\Support\Normalization\LearnerRecordNormalizer::normalizeProgramTitle('Diploma in Toggling'),
            'year_awarded' => 2024,
            'source_type' => \App\Enums\LearnerRecordSourceType::Manual,
            'is_active' => true,
        ]);

        $this->actingAs($applicant)
            ->get('/applicant/reference/awarding-institutions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $institution->id);

        $this->actingAs($admin)
            ->post("/admin/settings/awarding-institutions/{$institution->id}/deactivate")
            ->assertRedirect();

        $institution->refresh();
        $this->assertFalse((bool) $institution->is_active);

        $this->actingAs($applicant)
            ->get('/applicant/reference/awarding-institutions')
            ->assertOk()
            ->assertJsonMissing(['id' => $institution->id]);

        // Learner records remain accessible to admins (historical data preserved).
        $this->actingAs($admin)
            ->get("/admin/settings/awarding-institutions/{$institution->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.learner_records_total', 1)
            );

        $this->actingAs($admin)
            ->post("/admin/settings/awarding-institutions/{$institution->id}/reactivate")
            ->assertRedirect();

        $institution->refresh();
        $this->assertTrue((bool) $institution->is_active);

        $this->actingAs($applicant)
            ->get('/applicant/reference/awarding-institutions')
            ->assertOk()
            ->assertJsonFragment(['id' => $institution->id]);
    }

    public function test_only_authorized_users_can_deactivate_or_reactivate(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $adminViewOnly = User::factory()->activated()->create(['applicant_type' => null]);
        $adminViewOnly->givePermissionTo('dashboard.view');
        $adminViewOnly->givePermissionTo('settings.awarding_institutions.view');

        $country = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]);
        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Permission University',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($adminViewOnly)
            ->post("/admin/settings/awarding-institutions/{$institution->id}/deactivate")
            ->assertStatus(403);

        $this->actingAs($adminViewOnly)
            ->post("/admin/settings/awarding-institutions/{$institution->id}/reactivate")
            ->assertStatus(403);
    }

    public function test_recent_qualification_activity_is_limited_to_10(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo('dashboard.view');
        $admin->givePermissionTo('settings.awarding_institutions.view');

        $country = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]);
        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Activity University',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-AI-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
        ]);

        for ($i = 0; $i < 15; $i++) {
            Qualification::query()->create([
                'application_id' => $application->id,
                'awarding_institution_id' => $institution->id,
                'awarding_institution_name' => $institution->name,
                'qualification_holder_name' => 'John Doe',
                'nrc_passport_number' => '111111/11/1',
                'student_number' => 'STU-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'title_of_qualification' => 'Diploma #'.$i,
                'award_date' => '2024-01-10',
                'qualification_type' => 'L6',
                'verification_state' => VerificationState::AwaitingAssignment,
                'is_foreign_qualification' => false,
                'transcript_required' => false,
            ]);
        }

        $this->actingAs($admin)
            ->get("/admin/settings/awarding-institutions/{$institution->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recent_qualifications', 10)
            );
    }
}

