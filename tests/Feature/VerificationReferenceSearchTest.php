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

class VerificationReferenceSearchTest extends TestCase
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

    private function makeLevel2Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');

        return $user;
    }

    private function makeLevel1Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');

        return $user;
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
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        return [$matchApp, $matchQual];
    }

    /** @return list<int> */
    private function qualificationIdsFromResponse($response): array
    {
        return collect($this->inertiaProps($response)['qualifications']['data'] ?? [])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function test_verification_pool_filters_by_application_reference(): void
    {
        [$matchApp] = $this->seedMatchingAndNoiseRecords();
        $l2 = $this->makeLevel2Officer();

        $response = $this->actingAs($l2)->get(route('admin.verification.pool.index', [
            'application_reference' => '2026-000245',
        ]));

        $response->assertOk();
        $ids = $this->qualificationIdsFromResponse($response);
        $this->assertNotEmpty($ids);
        $this->assertTrue(
            Qualification::query()->whereIn('id', $ids)->where('application_id', $matchApp->id)->exists()
        );
    }

    public function test_verification_pool_filters_by_qualification_reference(): void
    {
        [, $matchQual] = $this->seedMatchingAndNoiseRecords();
        $l2 = $this->makeLevel2Officer();

        $response = $this->actingAs($l2)->get(route('admin.verification.pool.index', [
            'qualification_reference' => '2026-000245-01',
        ]));

        $response->assertOk();
        $this->assertContains($matchQual->id, $this->qualificationIdsFromResponse($response));
    }

    public function test_assigned_to_me_filters_by_application_reference(): void
    {
        [$matchApp] = $this->seedMatchingAndNoiseRecords();
        $l1 = $this->makeLevel1Officer();
        Qualification::query()->where('application_id', $matchApp->id)->update([
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $response = $this->actingAs($l1)->get(route('admin.verification.assigned_to_me', [
            'application_reference' => '2026-000245',
        ]));

        $response->assertOk();
        $this->assertNotEmpty($this->qualificationIdsFromResponse($response));
    }

    public function test_assigned_to_me_filters_by_qualification_reference(): void
    {
        [, $matchQual] = $this->seedMatchingAndNoiseRecords();
        $l1 = $this->makeLevel1Officer();
        $matchQual->update([
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $response = $this->actingAs($l1)->get(route('admin.verification.assigned_to_me', [
            'qualification_reference' => '2026-000245-01',
        ]));

        $response->assertOk();
        $this->assertContains($matchQual->id, $this->qualificationIdsFromResponse($response));
    }

    public function test_awaiting_level1_filters_by_application_reference(): void
    {
        [$matchApp] = $this->seedMatchingAndNoiseRecords();
        $l2 = $this->makeLevel2Officer();

        $response = $this->actingAs($l2)->get(route('admin.verification.awaiting_level1_assignment', [
            'application_reference' => '2026-000245',
        ]));

        $response->assertOk();
        $this->assertNotEmpty($this->qualificationIdsFromResponse($response));
        $this->assertTrue(
            Qualification::query()
                ->whereIn('id', $this->qualificationIdsFromResponse($response))
                ->where('application_id', $matchApp->id)
                ->exists()
        );
    }

    public function test_awaiting_level1_filters_by_qualification_reference(): void
    {
        [, $matchQual] = $this->seedMatchingAndNoiseRecords();
        $l2 = $this->makeLevel2Officer();

        $response = $this->actingAs($l2)->get(route('admin.verification.awaiting_level1_assignment', [
            'qualification_reference' => '2026-000245-01',
        ]));

        $response->assertOk();
        $this->assertContains($matchQual->id, $this->qualificationIdsFromResponse($response));
    }

    public function test_awaiting_level2_filters_by_application_reference(): void
    {
        [$matchApp] = $this->seedMatchingAndNoiseRecords();
        $l2 = $this->makeLevel2Officer();
        Qualification::query()->where('application_id', $matchApp->id)->update([
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => null,
        ]);

        $response = $this->actingAs($l2)->get(route('admin.verification.awaiting_level2_assignment', [
            'application_reference' => '2026-000245',
        ]));

        $response->assertOk();
        $this->assertNotEmpty($this->qualificationIdsFromResponse($response));
    }

    public function test_awaiting_level2_filters_by_qualification_reference(): void
    {
        [, $matchQual] = $this->seedMatchingAndNoiseRecords();
        $l2 = $this->makeLevel2Officer();
        $matchQual->update([
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => null,
        ]);

        $response = $this->actingAs($l2)->get(route('admin.verification.awaiting_level2_assignment', [
            'qualification_reference' => '2026-000245-01',
        ]));

        $response->assertOk();
        $this->assertContains($matchQual->id, $this->qualificationIdsFromResponse($response));
    }

    public function test_holder_name_broad_search_no_longer_matches_verification_pool(): void
    {
        $this->seedMatchingAndNoiseRecords();
        $l2 = $this->makeLevel2Officer();

        $referenceFiltered = $this->actingAs($l2)->get(route('admin.verification.pool.index', [
            'application_reference' => '2026-000245',
        ]));
        $ignoredQ = $this->actingAs($l2)->get(route('admin.verification.pool.index', [
            'q' => 'Unique Holder Name XYZ',
        ]));

        $referenceIds = $this->qualificationIdsFromResponse($referenceFiltered);
        $ignoredQIds = $this->qualificationIdsFromResponse($ignoredQ);

        $this->assertCount(1, $referenceIds);
        $this->assertGreaterThan(1, count($ignoredQIds));
        $this->assertNotEquals($referenceIds, $ignoredQIds);
    }

    public function test_nrc_broad_search_no_longer_matches_verification_pool(): void
    {
        $this->seedMatchingAndNoiseRecords();
        $l2 = $this->makeLevel2Officer();

        $referenceFiltered = $this->actingAs($l2)->get(route('admin.verification.pool.index', [
            'qualification_reference' => '2026-000245-01',
        ]));
        $ignoredQ = $this->actingAs($l2)->get(route('admin.verification.pool.index', [
            'q' => '999999/99/9',
        ]));

        $referenceIds = $this->qualificationIdsFromResponse($referenceFiltered);
        $ignoredQIds = $this->qualificationIdsFromResponse($ignoredQ);

        $this->assertCount(1, $referenceIds);
        $this->assertGreaterThan(1, count($ignoredQIds));
    }

    public function test_prefix_search_does_not_require_leading_wildcard(): void
    {
        [, $matchQual] = $this->seedMatchingAndNoiseRecords();
        $l2 = $this->makeLevel2Officer();

        $response = $this->actingAs($l2)->get(route('admin.verification.pool.index', [
            'application_reference' => '2026-000',
        ]));

        $response->assertOk();
        $this->assertContains($matchQual->id, $this->qualificationIdsFromResponse($response));
    }

    public function test_old_q_parameter_does_not_trigger_broad_search(): void
    {
        $this->seedMatchingAndNoiseRecords();
        $l2 = $this->makeLevel2Officer();

        $viaQ = $this->actingAs($l2)->get(route('admin.verification.pool.index', [
            'q' => '2026-000245',
        ]));
        $viaReference = $this->actingAs($l2)->get(route('admin.verification.pool.index', [
            'application_reference' => '2026-000245',
        ]));

        $viaQ->assertOk();
        $viaReference->assertOk();

        $this->assertCount(1, $this->qualificationIdsFromResponse($viaReference));
        $this->assertGreaterThan(1, count($this->qualificationIdsFromResponse($viaQ)));
    }

    public function test_ui_page_props_include_split_filter_values(): void
    {
        $l2 = $this->makeLevel2Officer();

        $response = $this->actingAs($l2)->get(route('admin.verification.pool.index', [
            'application_reference' => '2026-000245',
            'qualification_reference' => '2026-000245-01',
        ]));

        $filters = $this->inertiaProps($response)['filters'];
        $this->assertSame('2026-000245', $filters['application_reference']);
        $this->assertSame('2026-000245-01', $filters['qualification_reference']);
        $this->assertArrayNotHasKey('q', $filters);
    }
}
