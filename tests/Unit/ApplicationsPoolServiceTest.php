<?php

namespace Tests\Unit;

use App\Domain\Verification\ApplicationsPoolService;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationsPoolServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_by_awarding_institution_counts_local_qualifications_when_application_flag_is_foreign(): void
    {
        $country = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia Test',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'University of Testland',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-MIX-001',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => true,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
        ]);

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'Jane Doe',
            'country_id' => $country->id,
            'country_name_other' => null,
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        /** @var ApplicationsPoolService $svc */
        $svc = $this->app->make(ApplicationsPoolService::class);
        $groups = $svc->byAwardingInstitutionCounts();

        $match = collect($groups)->firstWhere('awarding_institution_id', $institution->id);
        $this->assertNotNull($match);
        $this->assertSame(1, $match['count']);
        $this->assertSame(1, $match['local_count']);
        $this->assertSame(0, $match['foreign_count']);

        $localOnly = $svc->byAwardingInstitutionCounts(['locality' => 'local']);
        $matchLocal = collect($localOnly)->firstWhere('awarding_institution_id', $institution->id);
        $this->assertNotNull($matchLocal);
        $this->assertSame(1, $matchLocal['count']);
        $this->assertSame(1, $matchLocal['local_count']);
        $this->assertSame(0, $matchLocal['foreign_count']);
    }

    public function test_by_awarding_institution_locality_filter_splits_local_and_foreign_rows(): void
    {
        $country = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia Mix',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Mixed University',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-MIX-002',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => true,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
        ]);

        $base = [
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'Holder',
            'country_id' => $country->id,
            'country_name_other' => null,
            'nrc_passport_number' => '333333/33/3',
            'title_of_qualification' => 'Cert',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'transcript_required' => false,
        ];

        Qualification::query()->create(array_merge($base, [
            'qualification_holder_name' => 'Local Holder',
            'is_foreign_qualification' => false,
        ]));
        Qualification::query()->create(array_merge($base, [
            'qualification_holder_name' => 'Foreign Holder',
            'is_foreign_qualification' => true,
        ]));

        /** @var ApplicationsPoolService $svc */
        $svc = $this->app->make(ApplicationsPoolService::class);

        $id = $institution->id;
        $all = collect($svc->byAwardingInstitutionCounts(['locality' => 'all']));
        $local = collect($svc->byAwardingInstitutionCounts(['locality' => 'local']));
        $foreign = collect($svc->byAwardingInstitutionCounts(['locality' => 'foreign']));

        $rowAll = $all->firstWhere('awarding_institution_id', $id);
        $this->assertSame(2, $rowAll['count']);
        $this->assertSame(1, $rowAll['local_count']);
        $this->assertSame(1, $rowAll['foreign_count']);

        $rowLocal = $local->firstWhere('awarding_institution_id', $id);
        $this->assertSame(1, $rowLocal['count']);
        $this->assertSame(1, $rowLocal['local_count']);
        $this->assertSame(1, $rowLocal['foreign_count']);

        $rowForeign = $foreign->firstWhere('awarding_institution_id', $id);
        $this->assertSame(1, $rowForeign['count']);
        $this->assertSame(1, $rowForeign['local_count']);
        $this->assertSame(1, $rowForeign['foreign_count']);
    }
}
