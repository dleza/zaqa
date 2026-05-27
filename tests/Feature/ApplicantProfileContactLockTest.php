<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicantProfileContactLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_verified_email_cannot_be_changed_from_applicant_profile_edit(): void
    {
        $user = User::factory()->create([
            'applicant_type' => ApplicantType::Individual,
            'is_active' => true,
            'email' => 'locked@example.test',
            'email_verified_at' => now(),
            'phone_primary' => '+260955000111',
            'phone_verified_at' => null,
        ]);

        $this->actingAs($user);

        $response = $this->from('/applicant/profile/edit')->put('/applicant/profile', [
            'email' => 'new@example.test',
            'phone_primary' => $user->phone_primary,
            'phone_secondary' => null,
            'first_name' => 'Jane',
            'surname' => 'Doe',
            'nrc_number' => '123456/78/9',
            'passport_number' => null,
        ]);

        $response->assertRedirect('/applicant/profile/edit');
        $response->assertSessionHasErrors(['email']);

        $user->refresh();
        $this->assertSame('locked@example.test', $user->email);
    }

    public function test_verified_primary_phone_cannot_be_changed_from_applicant_profile_edit(): void
    {
        $user = User::factory()->create([
            'applicant_type' => ApplicantType::Individual,
            'is_active' => true,
            'email' => 'jane@example.test',
            'email_verified_at' => null,
            'phone_primary' => '+260955000222',
            'phone_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->from('/applicant/profile/edit')->put('/applicant/profile', [
            'email' => $user->email,
            'phone_primary' => '+260955999000',
            'phone_secondary' => null,
            'first_name' => 'Jane',
            'surname' => 'Doe',
            'nrc_number' => '123456/78/9',
            'passport_number' => null,
        ]);

        $response->assertRedirect('/applicant/profile/edit');
        $response->assertSessionHasErrors(['phone_primary']);

        $user->refresh();
        $this->assertSame('+260955000222', $user->phone_primary);
    }
}

