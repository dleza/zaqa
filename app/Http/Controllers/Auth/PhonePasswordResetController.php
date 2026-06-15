<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\PasswordResetService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordWithOtpRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PhonePasswordResetController extends Controller
{
    public function create(Request $request, PasswordResetService $passwordReset): Response|RedirectResponse
    {
        $userId = (int) $request->session()->get('password_reset_user_id', 0);
        $user = $passwordReset->userFromPasswordResetSession($userId);

        if (! $user) {
            return redirect()
                ->route('password.request')
                ->with('error', 'Start password reset again using your email or phone number.');
        }

        return Inertia::render('Auth/ResetPasswordPhone', [
            'phone_hint' => (string) $request->session()->get(
                'password_reset_phone_hint',
                $passwordReset->maskPhone((string) $user->phone_primary),
            ),
        ]);
    }

    public function store(
        ResetPasswordWithOtpRequest $request,
        PasswordResetService $passwordReset,
    ): RedirectResponse {
        $userId = (int) $request->session()->get('password_reset_user_id', 0);
        $user = $passwordReset->userFromPasswordResetSession($userId);

        if (! $user) {
            return redirect()
                ->route('password.request')
                ->with('error', 'Your password reset session has expired. Please try again.');
        }

        $validated = $request->validated();
        $passwordReset->resetPasswordWithOtp($user, (string) $validated['code'], (string) $validated['password']);

        $request->session()->forget(['password_reset_user_id', 'password_reset_phone_hint']);

        return redirect()
            ->route('login')
            ->with('success', 'Password reset successfully. Please log in.');
    }

    public function resend(Request $request, PasswordResetService $passwordReset): RedirectResponse
    {
        $userId = (int) $request->session()->get('password_reset_user_id', 0);
        $user = $passwordReset->userFromPasswordResetSession($userId);

        if (! $user) {
            return redirect()
                ->route('password.request')
                ->with('error', 'Your password reset session has expired. Please try again.');
        }

        $passwordReset->issuePasswordResetOtp($user);

        return back()->with('success', 'A new verification code has been sent to your phone number.');
    }
}
