<?php

namespace Tests\Feature;

use App\Mail\InstitutionApiTokenIssuedMail;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionApiClient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminInstitutionApiClientTokenManagementTest extends TestCase
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

    public function test_super_admin_can_create_client_issue_and_email_token_and_rotate(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Mail::fake();

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $institution = $this->makeInstitution('Test University');

        $this->actingAs($admin)->post('/admin/integrations/institution-api-clients', [
            'awarding_institution_id' => (int) $institution->id,
            'name' => 'Test Client',
            'contact_name' => 'ICT Officer',
            'contact_email' => 'integration@test.edu',
            'is_active' => true,
            'scopes' => ['learner-records:write', 'learner-records:read', 'learner-records:lookup', 'learner-records:batch', 'learner-records:status'],
            'notes' => 'Test notes',
        ])->assertRedirect();

        $client = InstitutionApiClient::query()->firstOrFail();

        $issue = $this->actingAs($admin)->post("/admin/integrations/institution-api-clients/{$client->id}/tokens", [
            'token_name' => 't1',
            'abilities' => ['learner-records:write'],
            'expires_in_days' => 30,
        ]);

        $issue->assertRedirect();
        $issue->assertSessionHas('institution_api_plaintext_token');
        $follow = $this->actingAs($admin)->get("/admin/integrations/institution-api-clients/{$client->id}");
        $props = json_decode(json_encode($follow->viewData('page')), true)['props'];
        $token = (string) ($props['flash_token'] ?? '');
        $this->assertNotSame('', $token);
        $this->assertStringContainsString('|', $token);
        $this->assertNotNull(\Laravel\Sanctum\PersonalAccessToken::findToken($token));

        $this->actingAs($admin)->post("/admin/integrations/institution-api-clients/{$client->id}/tokens/email-latest", [
            'token' => $token,
            'abilities' => ['learner-records:write'],
        ])->assertRedirect();

        Mail::assertSent(InstitutionApiTokenIssuedMail::class, function (InstitutionApiTokenIssuedMail $m) use ($client, $token) {
            return $m->hasTo('integration@test.edu')
                && $m->client->is($client)
                && $m->plainTextToken === $token;
        });

        // Old token works.
        $this->actingAsGuest();
        $this->postJson('/api/institution/v1/learner-records', [
            'student_id' => 'STU-500',
            'first_name' => 'Mary',
            'last_name' => 'Banda',
            'program_of_study' => 'BSc Nursing',
            'year_awarded' => 2024,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);
        $this->actingAs($admin);

        // Rotate token revokes old.
        $rotate = $this->actingAs($admin)->post("/admin/integrations/institution-api-clients/{$client->id}/tokens/rotate", [
            'token_name' => 't2',
            'abilities' => ['learner-records:write'],
            'expires_in_days' => 30,
        ]);
        $rotate->assertRedirect();
        $rotate->assertSessionHas('institution_api_plaintext_token');
        $follow2 = $this->actingAs($admin)->get("/admin/integrations/institution-api-clients/{$client->id}");
        $props2 = json_decode(json_encode($follow2->viewData('page')), true)['props'];
        $newToken = (string) ($props2['flash_token'] ?? '');
        $this->assertNotSame('', $newToken);
        $this->assertNotSame($token, $newToken);

        // Old token no longer works.
        $this->actingAsGuest();
        $this->postJson('/api/institution/v1/learner-records', [
            'student_id' => 'STU-501',
            'first_name' => 'Mary',
            'last_name' => 'Banda',
            'program_of_study' => 'BSc Nursing',
            'year_awarded' => 2024,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(401);

        // New token works.
        $this->postJson('/api/institution/v1/learner-records', [
            'student_id' => 'STU-502',
            'first_name' => 'Mary',
            'last_name' => 'Banda',
            'program_of_study' => 'BSc Nursing',
            'year_awarded' => 2024,
        ], ['Authorization' => 'Bearer '.$newToken])->assertStatus(201);
    }

    public function test_plain_token_is_only_visible_once_after_generation(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $institution = $this->makeInstitution('One Time University');

        $client = InstitutionApiClient::query()->create([
            'awarding_institution_id' => (int) $institution->id,
            'name' => 'Client',
            'is_active' => true,
            'scopes' => ['learner-records:write'],
        ]);

        $issue = $this->actingAs($admin)->post("/admin/integrations/institution-api-clients/{$client->id}/tokens", [
            'token_name' => 't1',
            'abilities' => ['learner-records:write'],
            'expires_in_days' => 30,
        ]);
        $issue->assertRedirect();

        $firstShow = $this->actingAs($admin)->get("/admin/integrations/institution-api-clients/{$client->id}");
        $firstShow->assertOk();
        $props1 = json_decode(json_encode($firstShow->viewData('page')), true)['props'];
        $this->assertNotEmpty($props1['flash_token']);

        $secondShow = $this->actingAs($admin)->get("/admin/integrations/institution-api-clients/{$client->id}");
        $secondShow->assertOk();
        $props2 = json_decode(json_encode($secondShow->viewData('page')), true)['props'];
        $this->assertTrue(empty($props2['flash_token']));
    }
}
