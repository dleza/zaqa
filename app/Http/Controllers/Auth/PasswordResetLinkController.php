<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\PasswordResetService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordWithOtpRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(ForgotPasswordRequest $request, PasswordResetService $passwordReset): RedirectResponse
    {
        $identifier = (string) $request->validated()['identifier'];
        $user = $passwordReset->findUserByIdentifier($identifier);

        if (! $user) {
            throw ValidationException::withMessages([
                'identifier' => 'No account found with this email or phone number.',
            ]);
        }

        $result = $passwordReset->requestReset($user, $identifier);

        if ($result['channel'] === 'phone') {
            $request->session()->put('password_reset_user_id', (int) $user->id);

            return redirect()
                ->route('password.reset.phone')
                ->with('success', $result['message'])
                ->with('password_reset_phone_hint', $result['phone_hint']);
        }

        return back()->with('success', $result['message']);
    }
}
