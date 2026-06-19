<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SessionInactivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('session.driver', 'database');
        Config::set('session.lifetime', 15);
        $this->resetSessionStore();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_session_lifetime_defaults_to_fifteen_minutes(): void
    {
        $this->assertSame(15, (int) config('session.lifetime'));
    }

    public function test_unauthenticated_user_is_redirected_from_applicant_dashboard(): void
    {
        $this->get(route('applicant.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_active_within_fifteen_minutes_remains_logged_in(): void
    {
        $user = $this->makeApplicant();
        $sessionId = $this->loginAs($user);

        DB::table('sessions')->where('id', $sessionId)->update([
            'last_activity' => now()->subMinutes(14)->getTimestamp(),
        ]);

        $this->get(route('applicant.dashboard'))
            ->assertOk();
    }

    public function test_authenticated_user_inactive_beyond_fifteen_minutes_is_logged_out(): void
    {
        $user = $this->makeApplicant();
        $sessionId = $this->loginAs($user);

        DB::table('sessions')->where('id', $sessionId)->update([
            'last_activity' => now()->subMinutes(16)->getTimestamp(),
        ]);

        $this->resetSessionStore();
        $this->app['auth']->forgetGuards();

        $this->get(route('applicant.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_expired_session_redirects_to_login_with_friendly_message(): void
    {
        $request = Request::create(route('applicant.dashboard'), 'GET');
        $request->cookies->set(config('session.cookie'), 'expired-session-id');

        $response = TestResponse::fromBaseResponse(
            app(\Illuminate\Contracts\Debug\ExceptionHandler::class)
                ->render($request, new AuthenticationException())
        );

        $response->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your session expired due to inactivity. Please log in again.');
    }

    public function test_token_mismatch_redirects_to_login_with_friendly_message(): void
    {
        $request = Request::create('/applicant/notifications/read-all', 'POST');
        $request->setLaravelSession($this->app->make('session.store'));

        $response = TestResponse::fromBaseResponse(
            app(\Illuminate\Contracts\Debug\ExceptionHandler::class)
                ->render($request, new TokenMismatchException('CSRF token mismatch.'))
        );

        $response->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your session expired due to inactivity. Please log in again.');
    }

    public function test_database_session_driver_expires_idle_sessions_after_fifteen_minutes(): void
    {
        $sessionId = 'expired-session-id';

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->subMinutes(16)->getTimestamp(),
        ]);

        $payload = app('session')->getHandler()->read($sessionId);

        $this->assertSame('', $payload);
    }

    private function resetSessionStore(): void
    {
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance(\Illuminate\Session\SessionManager::class);
    }

    private function makeApplicant(): User
    {
        return User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'email' => 'applicant-'.uniqid().'@example.test',
        ]);
    }

    private function loginAs(User $user): string
    {
        $response = $this->post(route('login.store'), [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('applicant.dashboard'));

        $sessionId = (string) $response->getCookie(config('session.cookie'))?->getValue();
        $this->assertNotSame('', $sessionId);
        $this->assertDatabaseHas('sessions', ['id' => $sessionId]);

        return $sessionId;
    }
}
