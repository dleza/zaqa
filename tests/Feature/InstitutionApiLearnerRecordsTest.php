<?php

namespace Tests\Feature;

use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionApiClient;
use App\Models\LearnerRecord;
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

    public function test_institution_token_can_create_learner_record_scoped_to_institution(): void
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
            // Should be ignored (institution inferred from token).
            'awarding_institution_id' => (int) $instB->id,
        ];

        $res = $this->postJson('/api/institution/v1/learner-records', $payload, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $res->assertStatus(201)->assertJsonPath('success', true);

        $id = (int) ($res->json('data.learner_record_id') ?? 0);
        $this->assertGreaterThan(0, $id);

        $record = LearnerRecord::query()->findOrFail($id);
        $this->assertSame((int) $instA->id, (int) $record->awarding_institution_id);
        $this->assertSame('STU-100', $record->student_id);
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
