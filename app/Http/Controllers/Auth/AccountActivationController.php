<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogService;
use App\Domain\Identity\AccountActivationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyPhoneOtpRequest;
use App\Models\UserVerificationToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountActivationController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Auth/ActivateAccount', [
            'emailVerified' => (bool) $user?->email_verified_at,
            'phoneVerified' => (bool) $user?->phone_verified_at,
            'isActive' => (bool) $user?->is_active,
            'loginIdentifierType' => $user?->login_identifier_type,
            'hasEmail' => trim((string) ($user?->email ?? '')) !== '',
            'hasPhone' => trim((string) ($user?->phone_primary ?? '')) !== '',
        ]);
    }

    public function verifyEmail(Request $request, AccountActivationService $activation): RedirectResponse
    {
        $token = (string) $request->query('token', '');

        if ($token === '') {
            return redirect('/login')
                ->with('error', 'Activation token is missing.');
        }

        $tokenHash = hash('sha256', $token);

        $record = UserVerificationToken::query()
            ->where('type', 'email_activation')
            ->where('token_hash', $tokenHash)
            ->first();

        if ($record?->user?->email_verified_at) {
            Auth::login($record->user);

            return redirect('/activate')
                ->with('success', 'Your email address is already verified.');
        }

        try {
            $user = $activation->verifyEmailActivationToken($token);
        } catch (ValidationException $e) {
            return redirect('/login')
                ->with('error', 'The verification link is invalid or has expired. Please log in to request a new link.');
        }

        Auth::login($user);

        return redirect('/activate')
            ->with('success', 'Email verified successfully.');
    }

    public function verifyPhoneOtp(VerifyPhoneOtpRequest $request, AccountActivationService $activation): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $throttleKey = 'otp|'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Please try again later.',
            ]);
        }

        try {
            $activation->verifyPhoneOtp($user, (string) $request->validated()['code']);
            RateLimiter::clear($throttleKey);
        } catch (ValidationException $e) {
            RateLimiter::hit($throttleKey, 60);
            throw $e;
        }

        return redirect()->route('activation.show')
            ->with('success', 'Phone number verified successfully.');
    }

    public function resendEmail(Request $request, AccountActivationService $activation): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $throttleKey = 'resend-email|'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            return back()->with('error', 'Too many requests. Please try again later.');
        }

        RateLimiter::hit($throttleKey, 60);

        $activation->issueEmailActivationToken($user);

        return back()->with('success', 'Activation email resent.');
    }

    public function resendOtp(Request $request, AccountActivationService $activation, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $throttleKey = 'resend-otp|'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            return back()->with('error', 'Too many requests. Please try again later.');
        }

        RateLimiter::hit($throttleKey, 60);

        if (! $user->phone_primary) {
            $audit->record(
                eventType: 'identity.phone_otp_resend_failed',
                module: 'Identity',
                actionName: 'phone_otp_resend_failed',
                message: 'OTP resend failed because user has no phone number.',
                entityType: $user::class,
                entityId: $user->id,
                actor: $user,
            );

            return back()->with('error', 'No phone number found for this account.');
        }

        $activation->issuePhoneOtp($user);

        return back()->with('success', 'OTP code resent.');
    }
}

