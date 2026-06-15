<?php

namespace Tests\Feature;

use App\Domain\Identity\PasswordResetService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use App\Notifications\Auth\QueuedResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_forgot_password_returns_error_when_identifier_not_found(): void
    {
        $response = $this->from('/forgot-password')->post('/forgot-password', [
            'identifier' => 'missing@example.test',
        ]);

        $response->assertRedirect('/forgot-password');
        $response->assertSessionHasErrors('identifier');
        $this->assertSame(
            'No account found with this email or phone number.',
            session('errors')->get('identifier')[0] ?? null,
        );
    }

    public function test_forgot_password_sends_email_link_for_email_login_accounts(): void
    {
        Notification::fake();

        $user = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'login_identifier_type' => 'email',
            'email' => 'reset.me@example.test',
            'phone_primary' => '260955000222',
            'password' => Hash::make('OldPassword123'),
            'is_active' => true,
        ]);

        $response = $this->from('/forgot-password')->post('/forgot-password', [
            'identifier' => 'reset.me@example.test',
        ]);

        $response->assertRedirect('/forgot-password');
        $response->assertSessionHas('success', 'Password reset link sent to your email address.');
        Notification::assertSentTo($user, QueuedResetPasswordNotification::class);
    }

    public function test_forgot_password_sends_phone_otp_for_phone_login_accounts(): void
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'login_identifier_type' => 'phone',
            'email' => null,
            'phone_primary' => '260955000333',
            'password' => Hash::make('OldPassword123'),
            'is_active' => true,
        ]);

        $response = $this->post('/forgot-password', [
            'identifier' => '0955000333',
        ]);

        $response->assertRedirect(route('password.reset.phone'));
        $response->assertSessionHas('success', 'A verification code has been sent to your phone number.');
        $response->assertSessionHas('password_reset_user_id', $user->id);

        $this->assertDatabaseHas('user_phone_otps', [
            'user_id' => $user->id,
            'purpose' => PasswordResetService::OTP_PURPOSE_PASSWORD_RESET,
        ]);
    }

    public function test_phone_password_reset_updates_password_with_valid_otp(): void
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'login_identifier_type' => 'phone',
            'email' => null,
            'phone_primary' => '260955000444',
            'password' => Hash::make('OldPassword123'),
            'is_active' => true,
        ]);

        /** @var PasswordResetService $service */
        $service = app(PasswordResetService::class);
        $code = $service->issuePasswordResetOtp($user);

        $response = $this->withSession(['password_reset_user_id' => $user->id])
            ->post('/reset-password/phone', [
                'code' => $code,
                'password' => 'NewPassword123',
                'password_confirmation' => 'NewPassword123',
            ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success', 'Password reset successfully. Please log in.');

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword123', (string) $user->password));
    }

    public function test_phone_password_reset_page_requires_session(): void
    {
        $response = $this->get('/reset-password/phone');

        $response->assertRedirect(route('password.request'));
        $response->assertSessionHas('error');
    }
}
