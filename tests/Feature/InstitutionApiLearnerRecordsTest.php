<?php

namespace Tests\Feature;

use App\Domain\LearnerRecords\LearnerRecordSubmissionReviewService;
use App\Models\AuditLog;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionApiClient;
use App\Models\LearnerRecord;
use App\Models\LearnerRecordSubmission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionApiLearnerRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function makeInstitution(string $name): AwardingInstitution
    {
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]
        );

        return AwardingInstitution::query()->create([
            'country_id' => (int) $country->id,
            'name' => $name,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function makeClient(AwardingInstitution $institution, array $scopes = ['learner-records:write']): InstitutionApiClient
    {
        return InstitutionApiClient::query()->create([
            'awarding_institution_id' => (int) $institution->id,
            'name' => 'Test Client',
            'scopes' => $scopes,
            'is_active' => true,
        ]);
    }

    public function test_institution_api_requires_bearer_token(): void
    {
        $this->postJson('/api/institution/v1/learner-records', [])->assertStatus(401);
    }

    public function test_institution_token_creates_submission_not_learner_record(): void
    {
        $instA = $this->makeInstitution('Institution A');
        $instB = $this->makeInstitution('Institution B');

        $client = $this->makeClient($instA, ['learner-records:write']);
        $token = $client->createToken('t', ['learner-records:write'])->plainTextToken;

        $payload = [
            'student_id' => 'STU-100',
            'first_name' => 'Mary',
            'last_name' => 'Banda',
            'program_of_study' => 'BSc Nursing',
            'year_awarded' => 2024,
            'awarding_institution_id' => (int) $instB->id,
        ];

        $res = $this->postJson('/api/institution/v1/learner-records', $payload, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $res->assertStatus(202)
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('message', 'Record received and pending ZAQA review.');

        $submissionId = (int) ($res->json('submission_id') ?? 0);
        $this->assertGreaterThan(0, $submissionId);
        $this->assertSame(0, LearnerRecord::query()->count());
        $this->assertSame(1, LearnerRecordSubmission::query()->count());

        $submission = LearnerRecordSubmission::query()->findOrFail($submissionId);
        $this->assertSame((int) $instA->id, (int) $submission->source_institution_id);
        $this->assertSame('STU-100', $submission->student_id);
        $this->assertSame('pending', $submission->status?->value);
    }

    public function test_token_without_write_ability_cannot_create_records(): void
    {
        $inst = $this->makeInstitution('Institution A');
        $client = $this->makeClient($inst, ['learner-records:read']);
        $token = $client->createToken('t', ['learner-records:read'])->plainTextToken;

        $this->postJson('/api/institution/v1/learner-records', [
            'student_id' => 'STU-101',
            'first_name' => 'Mary',
            'last_name' => 'Banda',
            'program_of_study' => 'BSc Nursing',
            'year_awarded' => 2024,
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(403);
    }

    public function test_batch_push_creates_submissions_and_returns_pending_message(): void
    {
        $inst = $this->makeInstitution('Institution A');
        $client = $this->makeClient($inst, ['learner-records:batch']);
        $token = $client->createToken('t', ['learner-records:batch'])->plainTextToken;

        $res = $this->postJson('/api/institution/v1/learner-records/batch', [
            'records' => [
                [
                    'student_id' => 'STU-200',
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'program_of_study' => 'BSc Nursing',
                    'year_awarded' => 2024,
                ],
                [
                    'student_id' => 'STU-201',
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'program_of_study' => 'BSc Nursing',
                    'year_awarded' => 2024,
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('pending_review', 2)
            ->assertJsonPath('message', 'Records received and pending ZAQA review.');

        $this->assertSame(0, LearnerRecord::query()->count());
        $this->assertSame(2, LearnerRecordSubmission::query()->where('status', 'pending')->count());
    }

    public function test_validation_errors_do_not_create_learner_records(): void
    {
        $inst = $this->makeInstitution('Institution A');
        $client = $this->makeClient($inst, ['learner-records:write']);
        $token = $client->createToken('t', ['learner-records:write'])->plainTextToken;

        $this->postJson('/api/institution/v1/learner-records', [
            'first_name' => 'Mary',
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(422);

        $this->assertSame(0, LearnerRecord::query()->count());
    }

    public function test_search_only_returns_records_for_authenticated_institution(): void
    {
        $instA = $this->makeInstitution('Institution A');
        $instB = $this->makeInstitution('Institution B');

        LearnerRecord::query()->create([
            'awarding_institution_id' => (int) $instA->id,
            'student_id' => 'STU-200',
            'student_id_normalized' => 'STU200',
            'first_name' => 'Mary',
            'last_name' => 'Banda',
            'program_of_study' => 'BSc Nursing',
            'qualification_title_normalized' => 'BSCNURSING',
            'year_awarded' => 2024,
            'source_type' => 'manual',
            'is_active' => true,
        ]);

        LearnerRecord::query()->create([
            'awarding_institution_id' => (int) $instB->id,
            'student_id' => 'STU-200',
            'student_id_normalized' => 'STU200',
            'first_name' => 'Mary',
            'last_name' => 'Banda',
            'program_of_study' => 'BSc Nursing',
            'qualification_title_normalized' => 'BSCNURSING',
            'year_awarded' => 2024,
            'source_type' => 'manual',
            'is_active' => true,
        ]);

        $client = $this->makeClient($instA, ['learner-records:lookup']);
        $token = $client->createToken('t', ['learner-records:lookup'])->plainTextToken;

        $res = $this->getJson('/api/institution/v1/learner-records/search?student_id=STU-200', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $res->assertStatus(200)->assertJsonPath('success', true);
        $items = $res->json('data.items') ?? [];
        $this->assertCount(1, $items);
    }
}
