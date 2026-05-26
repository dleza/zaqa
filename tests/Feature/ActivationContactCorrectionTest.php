<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Models\ApplicantProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ActivationContactCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_user_can_update_email_during_activation_and_new_link_is_issued(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'applicant_type' => ApplicantType::Individual,
            'is_active' => false,
            'email_verified_at' => null,
        ]);

        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'Jane',
            'middle_name' => null,
            'surname' => 'Doe',
            'nrc_number' => null,
            'passport_number' => null,
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'phone_secondary' => null,
        ]);

        $this->actingAs($user);

        $newEmail = 'corrected.email@example.test';

        $response = $this->post('/activate/update-email', [
            'email' => $newEmail,
        ]);

        $response->assertRedirect();

        $user->refresh();

        $this->assertSame($newEmail, $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse((bool) $user->is_active);

        $this->assertDatabaseHas('applicant_profiles', [
            'user_id' => $user->id,
            'email' => $newEmail,
        ]);

        $this->assertDatabaseHas('user_verification_tokens', [
            'user_id' => $user->id,
            'type' => 'email_activation',
            'sent_to' => $newEmail,
        ]);
    }

    public function test_user_can_update_phone_during_activation_and_new_otp_is_issued(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'applicant_type' => ApplicantType::Individual,
            'is_active' => false,
            'phone_verified_at' => null,
        ]);

        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'Jane',
            'middle_name' => null,
            'surname' => 'Doe',
            'nrc_number' => null,
            'passport_number' => null,
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'phone_secondary' => null,
        ]);

        $this->actingAs($user);

        $newPhone = '+260977000222';

        $response = $this->post('/activate/update-phone', [
            'phone_primary' => $newPhone,
        ]);

        $response->assertRedirect();

        $user->refresh();

        $this->assertSame($newPhone, $user->phone_primary);
        $this->assertNull($user->phone_verified_at);
        $this->assertFalse((bool) $user->is_active);

        $this->assertDatabaseHas('applicant_profiles', [
            'user_id' => $user->id,
            'phone_primary' => $newPhone,
        ]);

        $this->assertDatabaseHas('user_phone_otps', [
            'user_id' => $user->id,
            'phone_number' => $newPhone,
        ]);
    }
}

