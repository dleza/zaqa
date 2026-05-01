<?php

namespace Tests\Feature;

use App\Domain\Identity\AccountActivationService;
use App\Enums\ApplicantType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApplicantAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_individual_registration_creates_profile_audit_and_activation_challenges(): void
    {
        Mail::fake();

        $payload = [
            'first_name' => 'Jane',
            'middle_name' => 'M',
            'surname' => 'Doe',
            'login_identifier_type' => 'email',
            'phone_primary' => '+260955000111',
            'email' => 'jane.doe@example.test',
            'password' => 'VeryStrongPassword123',
            'password_confirmation' => 'VeryStrongPassword123',
        ];

        $response = $this->post('/register/individual', $payload);

        $response->assertRedirect(route('activation.show'));

        $user = User::query()->where('email', $payload['email'])->firstOrFail();

        $this->assertSame(ApplicantType::Individual, $user->applicant_type);
        $this->assertFalse((bool) $user->is_active);

        $this->assertDatabaseHas('applicant_profiles', [
            'user_id' => $user->id,
            'first_name' => 'Jane',
            'surname' => 'Doe',
            'email' => $payload['email'],
            'phone_primary' => $payload['phone_primary'],
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'identity.applicant_registered',
            'module' => 'Identity',
            'entity_type' => User::class,
            'entity_id' => $user->id,
        ]);

        $this->assertDatabaseHas('user_verification_tokens', [
            'user_id' => $user->id,
            'type' => 'email_activation',
        ]);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $user->id,
            'template_key' => 'activation_email',
            'status' => 'sent',
        ]);
    }

    public function test_email_token_and_phone_otp_activate_account(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'applicant_type' => ApplicantType::Individual,
            'is_active' => false,
            'email_verified_at' => null,
            'phone_verified_at' => null,
        ]);

        /** @var AccountActivationService $activation */
        $activation = app(AccountActivationService::class);

        $token = $activation->issueEmailActivationToken($user);
        $otp = $activation->issuePhoneOtp($user);

        $emailResponse = $this->get('/activate/email?token='.$token);
        $emailResponse->assertRedirect(route('activation.show'));

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse((bool) $user->is_active);
        $this->assertAuthenticatedAs($user);

        $otpResponse = $this->post('/activate/phone-otp', [
            'code' => $otp,
        ]);
        $otpResponse->assertRedirect(route('activation.show'));

        $user->refresh();
        $this->assertNotNull($user->phone_verified_at);
        $this->assertTrue((bool) $user->is_active);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'identity.account_activated',
            'module' => 'Identity',
            'entity_type' => User::class,
            'entity_id' => $user->id,
        ]);
    }
}

