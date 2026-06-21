<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationTrackSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inertiaProps($response): array
    {
        $page = $response->viewData('page');

        return json_decode(json_encode($page), true)['props'];
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        return $admin;
    }

    private function makeSubmittedApplication(array $overrides = []): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'application_number' => '2026-000245',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => false,
            'metadata' => [
                'verification_subject' => [
                    'full_name' => 'Unique Holder Name XYZ',
                    'nrc_number' => '999999/99/9',
                ],
            ],
            'submitted_at' => now()->subDays(2),
            'service_deadline_at' => now()->addDays(7),
        ], $overrides));
    }

    private function makeQualification(Application $app, array $overrides = []): Qualification
    {
        return Qualification::query()->create(array_merge([
            'application_id' => $app->id,
            'verification_reference_number' => '2026-000245-01',
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Unique Holder Name XYZ',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '999999/99/9',
            'title_of_qualification' => 'Unique Diploma Title ABC',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'service_deadline_at' => now()->addDays(5),
        ], $overrides));
    }

    /** @return array{0: Application, 1: Qualification} */
    private function seedMatchingAndNoiseRecords(): array
    {
        $matchApp = $this->makeSubmittedApplication();
        $matchQual = $this->makeQualification($matchApp);

        $noiseApp = $this->makeSubmittedApplication([
            'application_number' => '2026-000999',
            'metadata' => ['verification_subject' => ['full_name' => 'Noise Applicant']],
        ]);
        $this->makeQualification($noiseApp, [
            'verification_reference_number' => '2026-000999-01',
            'qualification_holder_name' => 'Noise Holder',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Noise Diploma',
        ]);

        return [$matchApp, $matchQual];
    }

    public function test_track_search_by_application_reference_returns_matching_row(): void
    {
        [$matchApp] = $this->seedMatchingAndNoiseRecords();
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.applications.track.index', [
            'application_reference' => '2026-000245',
        ]));

        $response->assertOk();
        $props = $this->inertiaProps($response);

        $this->assertTrue($props['search']['performed']);
        $this->assertNull($props['search']['error']);
        $this->assertCount(1, $props['search']['results']);
        $this->assertSame($matchApp->id, $props['search']['results'][0]['id']);
        $this->assertSame('2026-000245', $props['search']['results'][0]['application_number']);
        $this->assertSame(
            route('admin.verification.applications.show', $matchApp),
            $props['search']['results'][0]['view_url']
        );
        $this->assertSame('Open application', $props['search']['results'][0]['view_label']);
    }

    public function test_track_search_by_qualification_reference_returns_matching_row(): void
    {
        [$matchApp, $matchQual] = $this->seedMatchingAndNoiseRecords();
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.applications.track.index', [
            'qualification_reference' => '2026-000245-01',
        ]));

        $response->assertOk();
        $props = $this->inertiaProps($response);

        $this->assertTrue($props['search']['performed']);
        $this->assertCount(1, $props['search']['results']);
        $this->assertSame($matchApp->id, $props['search']['results'][0]['id']);
        $this->assertSame($matchQual->verification_reference_number, $props['search']['results'][0]['matched_qualification_reference']);
        $this->assertSame(
            route('admin.verification.qualifications.show', $matchQual),
            $props['search']['results'][0]['view_url']
        );
        $this->assertSame('Open qualification', $props['search']['results'][0]['view_label']);
    }

    public function test_track_search_requires_minimum_prefix_length(): void
    {
        $this->seedMatchingAndNoiseRecords();
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.applications.track.index', [
            'application_reference' => '20',
        ]));

        $response->assertOk();
        $props = $this->inertiaProps($response);

        $this->assertTrue($props['search']['performed']);
        $this->assertNotNull($props['search']['error']);
        $this->assertSame([], $props['search']['results']);
    }

    public function test_track_search_does_not_match_nrc_or_name_as_reference(): void
    {
        $this->seedMatchingAndNoiseRecords();
        $admin = $this->makeAdmin();

        $viaNrc = $this->actingAs($admin)->get(route('admin.applications.track.index', [
            'application_reference' => '999999/99/9',
        ]));
        $viaName = $this->actingAs($admin)->get(route('admin.applications.track.index', [
            'application_reference' => 'Unique Holder Name XYZ',
        ]));

        $viaNrc->assertOk();
        $viaName->assertOk();

        $this->assertSame([], $this->inertiaProps($viaNrc)['search']['results']);
        $this->assertSame([], $this->inertiaProps($viaName)['search']['results']);
    }

    public function test_track_detail_loads_when_application_id_is_provided(): void
    {
        [$matchApp] = $this->seedMatchingAndNoiseRecords();
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.applications.track.index', [
            'application_id' => $matchApp->id,
        ]));

        $response->assertOk();
        $props = $this->inertiaProps($response);

        $this->assertFalse($props['search']['performed']);
        $this->assertSame($matchApp->id, $props['selected']['id']);
        $this->assertSame('2026-000245', $props['selected']['application_number']);
    }

    public function test_track_suggest_endpoint_uses_reference_only_search(): void
    {
        [$matchApp] = $this->seedMatchingAndNoiseRecords();
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->getJson(route('admin.applications.track.suggest', [
            'application_reference' => '2026-000245',
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $matchApp->id);
        $response->assertJsonPath('data.0.application_number', '2026-000245');
    }
}
