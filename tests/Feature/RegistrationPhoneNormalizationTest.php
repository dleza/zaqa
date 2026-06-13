<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationPhoneNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'registration.with_email' => true,
            'registration.with_sms' => true,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('normalizedPhoneProvider')]
    public function test_individual_registration_normalizes_phone_primary(string $input, string $expected): void
    {
        $response = $this->post('/register/individual', [
            'first_name' => 'Jane',
            'middle_name' => '',
            'surname' => 'Doe',
            'login_identifier_type' => 'phone',
            'phone_primary' => $input,
            'email' => '',
            'password' => 'VeryStrongPassword123',
            'password_confirmation' => 'VeryStrongPassword123',
        ]);

        $response->assertRedirect(route('activation.show'));

        $this->assertDatabaseHas('users', [
            'phone_primary' => $expected,
            'login_identifier_type' => 'phone',
        ]);

        $user = User::query()->where('phone_primary', $expected)->firstOrFail();

        $this->assertDatabaseHas('applicant_profiles', [
            'user_id' => $user->id,
            'phone_primary' => $expected,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizedPhoneProvider(): array
    {
        return [
            'nine digit local' => ['973936164', '260973936164'],
            'local trunk prefix' => ['0973936164', '260973936164'],
            'international with plus' => ['+260973936164', '260973936164'],
            'international without plus' => ['260973936164', '260973936164'],
        ];
    }

    public function test_individual_registration_rejects_invalid_phone_length(): void
    {
        $response = $this->post('/register/individual', [
            'first_name' => 'Jane',
            'middle_name' => '',
            'surname' => 'Doe',
            'login_identifier_type' => 'phone',
            'phone_primary' => '97393616',
            'email' => '',
            'password' => 'VeryStrongPassword123',
            'password_confirmation' => 'VeryStrongPassword123',
        ]);

        $response->assertSessionHasErrors('phone_primary');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_profile_update_normalizes_phone_primary(): void
    {
        $user = User::factory()->create([
            'applicant_type' => ApplicantType::Individual,
            'email' => 'jane.doe@gmail.com',
            'phone_primary' => null,
            'login_identifier_type' => 'email',
            'is_active' => true,
        ]);

        $user->applicantProfile()->create([
            'first_name' => 'Jane',
            'surname' => 'Doe',
            'email' => 'jane.doe@gmail.com',
        ]);

        $response = $this->from('/applicant/profile/edit')->actingAs($user)->put('/applicant/profile', [
            'email' => 'jane.doe@gmail.com',
            'phone_primary' => '973936164',
            'phone_secondary' => null,
            'first_name' => 'Jane',
            'surname' => 'Doe',
            'nrc_number' => '123456/78/9',
            'passport_number' => null,
        ]);

        $response->assertRedirect('/applicant/profile');

        $user->refresh();

        $this->assertSame('260973936164', $user->phone_primary);
    }

    public function test_profile_update_accepts_local_digits_for_legacy_verified_phone(): void
    {
        $user = User::factory()->create([
            'applicant_type' => ApplicantType::Individual,
            'email' => 'jane.doe@gmail.com',
            'phone_primary' => '+260973936164',
            'phone_verified_at' => now(),
            'login_identifier_type' => 'phone',
            'is_active' => true,
        ]);

        $user->applicantProfile()->create([
            'first_name' => 'Jane',
            'surname' => 'Doe',
            'email' => 'jane.doe@gmail.com',
            'phone_primary' => '+260973936164',
        ]);

        $response = $this->from('/applicant/profile/edit')->actingAs($user)->put('/applicant/profile', [
            'email' => 'jane.doe@gmail.com',
            'phone_primary' => '973936164',
            'phone_secondary' => null,
            'first_name' => 'Jane',
            'surname' => 'Doe',
            'nrc_number' => '123456/78/9',
            'passport_number' => null,
        ]);

        $response->assertRedirect('/applicant/profile');
        $response->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('260973936164', $user->phone_primary);
    }
}
