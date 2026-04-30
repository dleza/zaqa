<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UpdateApplicantPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class AdminProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $user?->loadMissing(['department']);

        return Inertia::render('Admin/Profile/Show', [
            'profile' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'phone_primary' => $user?->phone_primary,
                'phone_secondary' => $user?->phone_secondary,
                'department' => $user?->department ? ['id' => $user->department->id, 'name' => $user->department->name] : null,
                'roles' => method_exists($user, 'getRoleNames') ? $user->getRoleNames()->values()->all() : [],
                'last_login_at' => optional($user?->last_login_at)?->toIso8601String(),
                'created_at' => optional($user?->created_at)?->toIso8601String(),
            ],
        ]);
    }

    public function editPassword(): Response
    {
        return Inertia::render('Admin/Profile/ChangePassword');
    }

    public function updatePassword(UpdateApplicantPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect('/login');
        }

        $validated = $request->validated();

        if (! Hash::check((string) $validated['current_password'], (string) $user->password)) {
            return back()->withErrors([
                'current_password' => 'Your current password is incorrect.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make((string) $validated['password']),
        ])->save();

        return redirect('/admin/profile')
            ->with('success', 'Password updated successfully.');
    }
}

