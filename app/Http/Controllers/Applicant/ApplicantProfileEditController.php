<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Audit\AuditLogService;
use App\Enums\ApplicantType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UpdateApplicantProfileRequest;
use App\Models\ApplicantProfile;
use App\Models\InstitutionProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantProfileEditController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $user?->loadMissing(['applicantProfile', 'institutionProfile']);

        return Inertia::render('Applicant/EditProfile', [
            'profile' => $this->profilePayload($request),
        ]);
    }

    public function update(UpdateApplicantProfileRequest $request, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect('/login');
        }

        $user->loadMissing(['applicantProfile', 'institutionProfile']);

        $before = [
            'user' => $user->only(['name', 'email', 'phone_primary', 'phone_secondary', 'applicant_type']),
            'applicant_profile' => $user->applicantProfile?->toArray(),
            'institution_profile' => $user->institutionProfile?->toArray(),
        ];

        $validated = $request->validated();

        DB::transaction(function () use ($user, $validated) {
            $user->email = (string) $validated['email'];
            $user->phone_primary = (string) $validated['phone_primary'];
            $user->phone_secondary = $validated['phone_secondary'] ?? null;

            if ($user->applicant_type === ApplicantType::Institution) {
                $profile = $user->institutionProfile ?: new InstitutionProfile(['user_id' => $user->id]);

                $profile->institution_name = (string) $validated['institution_name'];
                $profile->tpin = $validated['tpin'] ?? null;
                $profile->contact_person_name = (string) $validated['contact_person_name'];
                $profile->email = (string) $validated['email'];
                $profile->phone_primary = (string) $validated['phone_primary'];
                $profile->phone_secondary = $validated['phone_secondary'] ?? null;
                $profile->address_line_1 = $validated['address_line_1'] ?? null;
                $profile->address_line_2 = $validated['address_line_2'] ?? null;
                $profile->city = $validated['city'] ?? null;
                $profile->province = $validated['province'] ?? null;
                $profile->postal_code = $validated['postal_code'] ?? null;
                $profile->country = $validated['country'] ?? null;
                $profile->save();

                $user->name = (string) $validated['institution_name'];
            } else {
                $profile = $user->applicantProfile ?: new ApplicantProfile(['user_id' => $user->id]);

                $profile->first_name = (string) $validated['first_name'];
                $profile->middle_name = $validated['middle_name'] ?? null;
                $profile->surname = (string) $validated['surname'];
                $profile->nrc_number = $validated['nrc_number'] ?? null;
                $profile->passport_number = $validated['passport_number'] ?? null;
                $profile->email = (string) $validated['email'];
                $profile->phone_primary = (string) $validated['phone_primary'];
                $profile->phone_secondary = $validated['phone_secondary'] ?? null;
                $profile->address_line_1 = $validated['address_line_1'] ?? null;
                $profile->address_line_2 = $validated['address_line_2'] ?? null;
                $profile->city = $validated['city'] ?? null;
                $profile->province = $validated['province'] ?? null;
                $profile->postal_code = $validated['postal_code'] ?? null;
                $profile->country = $validated['country'] ?? null;
                $profile->save();

                $user->name = trim(($profile->first_name ?? '').' '.($profile->surname ?? ''));
            }

            $user->save();
        });

        $user->refresh();
        $user->loadMissing(['applicantProfile', 'institutionProfile']);

        $after = [
            'user' => $user->only(['name', 'email', 'phone_primary', 'phone_secondary', 'applicant_type']),
            'applicant_profile' => $user->applicantProfile?->toArray(),
            'institution_profile' => $user->institutionProfile?->toArray(),
        ];

        $audit->record(
            eventType: 'applicants.profile_updated',
            module: 'Applicants',
            actionName: 'profile_updated',
            message: 'Applicant profile updated.',
            entityType: get_class($user),
            entityId: $user->id,
            beforeState: $before,
            afterState: $after,
            actor: $user,
        );

        return redirect('/applicant/profile')->with('success', 'Profile updated successfully.');
    }

    private function profilePayload(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'phone_secondary' => $user->phone_secondary,
            'applicant_type' => $user->applicant_type?->value ?? (string) $user->applicant_type,
            'applicant_profile' => $user->applicantProfile?->only([
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
            ]),
            'institution_profile' => $user->institutionProfile?->only([
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
        ];
    }
}

