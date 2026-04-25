<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(ForgotPasswordRequest $request, AuditLogService $audit): RedirectResponse
    {
        $email = (string) $request->validated()['email'];

        $user = User::query()->where('email', $email)->first();

        $audit->record(
            eventType: 'identity.password_reset_requested',
            module: 'Identity',
            actionName: 'password_reset_requested',
            message: 'Password reset requested.',
            entityType: $user ? $user::class : null,
            entityId: $user?->id,
            metadata: [
                'email' => $email,
            ],
            actor: $user,
        );

        Password::sendResetLink(['email' => $email]);

        return back()->with('success', 'If the email exists in our system, a password reset link has been sent.');
    }
}

