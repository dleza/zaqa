<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, AuditLogService $audit): RedirectResponse
    {
        $validated = $request->validated();

        $identifier = $validated['identifier'];
        $password = $validated['password'];
        $remember = (bool) ($validated['remember'] ?? false);

        $throttleKey = strtolower($identifier).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'identifier' => 'Too many login attempts. Please try again later.',
            ]);
        }

        $credentials = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? ['email' => $identifier, 'password' => $password]
            : ['phone_primary' => $identifier, 'password' => $password];

        if (! Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'identifier' => 'The provided credentials are incorrect.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();

        if ($user->disabled_at) {
            $audit->record(
                eventType: 'identity.login_blocked',
                module: 'Identity',
                actionName: 'login_blocked',
                message: 'Login blocked for disabled account.',
                entityType: $user::class,
                entityId: $user->id,
                actor: $user,
            );

            Auth::logout();

            return redirect('/login')
                ->with('error', 'This account is disabled.');
        }

        if (! $user->is_active) {
            return redirect('/activate');
        }

        // Admin/staff users land in admin area; applicants land in applicant area.
        if ($user->can('dashboard.view')) {
            return redirect('/admin/dashboard');
        }

        return redirect('/applicant/dashboard');
    }

    public function destroy(): RedirectResponse
    {
        Auth::logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/');
    }
}

