<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLearnerRecordsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_non_authorized_admin_cannot_view_learner_records_pages(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');

        $this->actingAs($user);

        $this->get('/admin/learner-records')->assertStatus(403);
        $this->get('/admin/learner-records/imports')->assertStatus(403);
    }
}

