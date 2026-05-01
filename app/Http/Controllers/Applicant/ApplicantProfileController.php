<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UpdateApplicantPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantProfileController extends Controller
{
    public function show(\Illuminate\Http\Request $request): Response
    {
        $user = $request->user();
        $user?->loadMissing(['applicantProfile', 'institutionProfile']);

        return Inertia::render('Applicant/Profile', [
            'profile' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'phone_primary' => $user?->phone_primary,
                'phone_secondary' => $user?->phone_secondary,
                'applicant_type' => $user?->applicant_type?->value ?? (string) ($user?->applicant_type ?? ''),
                'applicant_profile' => $user?->applicantProfile?->only([
                    'first_name',
                    'middle_name',
                    'surname',
                    'nrc_number',
                    'passport_number',
                    'address_line_1',
                    'address_line_2',
                    'city',
                    'province',
                    'postal_code',
                    'country',
                    'identity_document_original_name',
                    'identity_document_uploaded_at',
                    'identity_document_size_bytes',
                ]),
                'institution_profile' => $user?->institutionProfile?->only([
                    'institution_name',
                    'tpin',
                    'contact_person_name',
                    'address_line_1',
                    'address_line_2',
                    'city',
                    'province',
                    'postal_code',
                    'country',
                ]),
                'email_verified_at' => $user?->email_verified_at,
                'phone_verified_at' => $user?->phone_verified_at,
                'is_active' => (bool) ($user?->is_active ?? false),
            ],
        ]);
    }

    public function editPassword(): Response
    {
        return Inertia::render('Applicant/ChangePassword');
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

        return redirect('/applicant/profile')
            ->with('success', 'Password updated successfully.');
    }
}

