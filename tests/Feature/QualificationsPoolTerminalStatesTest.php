<?php

namespace Tests\Feature;

use App\Domain\Verification\QualificationsPoolService;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class QualificationsPoolTerminalStatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeInProgressApplication(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::InProgress,
            'verification_state' => VerificationState::UnderLevel1Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
        ]);
    }

    /**
     * The verification pool is an actionable task queue. Once a qualification reaches a terminal outcome
     * (approved/rejected/certificate issued/closed), it should not remain visible in the pool even if the
     * parent application still has other pending qualification items.
     */
    public function test_pool_excludes_terminal_qualification_states_by_default(): void
    {
        $viewer = User::factory()->activated()->create(['applicant_type' => null]);

        $application = $this->makeInProgressApplication();

        $awaiting = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_state' => VerificationState::AwaitingAssignment,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Awaiting',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $underL2 = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_state' => VerificationState::UnderLevel2Review,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Under L2',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $legacyNull = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '333333/33/3',
            'title_of_qualification' => 'Legacy null state',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $approved = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_state' => VerificationState::ApprovedForCertificate,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '444444/44/4',
            'title_of_qualification' => 'Approved',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $rejected = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_state' => VerificationState::Rejected,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '555555/55/5',
            'title_of_qualification' => 'Rejected',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $issued = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_state' => VerificationState::CertificateIssued,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '666666/66/6',
            'title_of_qualification' => 'Issued',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $closed = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_state' => VerificationState::Closed,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '777777/77/7',
            'title_of_qualification' => 'Closed',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $returned = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_state' => VerificationState::ReturnedToApplicant,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '888888/88/8',
            'title_of_qualification' => 'Returned',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $request = Request::create('/admin/verification/pool', 'GET');
        $request->setUserResolver(fn () => $viewer);

        /** @var QualificationsPoolService $pool */
        $pool = $this->app->make(QualificationsPoolService::class);
        $rows = $pool->pool($request, $viewer->id)->getCollection();
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($awaiting->id, $ids);
        $this->assertContains($underL2->id, $ids);
        $this->assertContains($legacyNull->id, $ids);

        $this->assertNotContains($approved->id, $ids);
        $this->assertNotContains($rejected->id, $ids);
        $this->assertNotContains($issued->id, $ids);
        $this->assertNotContains($closed->id, $ids);
        $this->assertNotContains($returned->id, $ids);
    }

    public function test_overdue_filter_uses_qualification_deadline_not_parent_application_deadline(): void
    {
        $viewer = User::factory()->activated()->create(['applicant_type' => null]);

        $application = $this->makeInProgressApplication();
        $application->forceFill([
            'service_deadline_at' => now()->addDays(60),
        ])->save();

        $overdueQualification = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_state' => VerificationState::UnderLevel1Review,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Local overdue',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '999999/99/1',
            'title_of_qualification' => 'Local overdue',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'service_started_at' => now()->subDays(20),
            'service_deadline_at' => now()->subDay(),
        ]);

        $notOverdueQualification = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_state' => VerificationState::UnderLevel1Review,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Foreign active',
            'country_name_other' => 'Kenya',
            'nrc_passport_number' => '999999/99/2',
            'title_of_qualification' => 'Foreign active',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => true,
            'transcript_required' => true,
            'service_started_at' => now()->subDays(20),
            'service_deadline_at' => now()->addDays(40),
        ]);

        $request = Request::create('/admin/verification/pool', 'GET', ['overdue' => '1']);
        $request->setUserResolver(fn () => $viewer);

        /** @var QualificationsPoolService $pool */
        $pool = $this->app->make(QualificationsPoolService::class);
        $rows = $pool->pool($request, $viewer->id)->getCollection();
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($overdueQualification->id, $ids);
        $this->assertNotContains($notOverdueQualification->id, $ids);
    }
}
