<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Audit\AuditLogService;
use App\Enums\ApplicantType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UpdateApplicantProfileRequest;
use App\Models\AuditLog;
use App\Models\ApplicantProfile;
use App\Models\InstitutionProfile;
use App\Models\User;
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
            'change_trail' => $user instanceof User ? $this->changeTrailPayload($user) : [],
        ]);
    }

    public function update(UpdateApplicantProfileRequest $request, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect('/login');
        }

        $user->loadMissing(['applicantProfile', 'institutionProfile']);

        $before = $this->auditProfileSnapshot($user);

        $validated = $request->validated();

        DB::transaction(function () use ($user, $validated) {
            $email = $this->nullIfBlank($validated['email'] ?? null);
            $phonePrimary = $this->nullIfBlank($validated['phone_primary'] ?? null);
            $phoneSecondary = $this->nullIfBlank($validated['phone_secondary'] ?? null);

            $user->email = $email;
            $user->phone_primary = $phonePrimary;
            $user->phone_secondary = $phoneSecondary;

            if ($user->applicant_type === ApplicantType::Institution) {
                $profile = $user->institutionProfile ?: new InstitutionProfile(['user_id' => $user->id]);

                $profile->institution_name = (string) $validated['institution_name'];
                $profile->tpin = $validated['tpin'] ?? null;
                $profile->contact_person_name = (string) $validated['contact_person_name'];
                $profile->email = $email;
                $profile->phone_primary = $phonePrimary;
                $profile->phone_secondary = $phoneSecondary;
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
                $profile->email = $email;
                $profile->phone_primary = $phonePrimary;
                $profile->phone_secondary = $phoneSecondary;
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

        $after = $this->auditProfileSnapshot($user);
        $changedFields = $this->changedFields($before, $after);

        $audit->record(
            eventType: 'applicants.profile_updated',
            module: 'Applicants',
            actionName: 'profile_updated',
            message: 'Applicant profile updated.',
            entityType: get_class($user),
            entityId: $user->id,
            beforeState: $before,
            afterState: $after,
            metadata: [
                'changed_fields' => $changedFields,
                'changed_count' => count($changedFields),
            ],
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
            'email_verified_at' => $user->email_verified_at,
            'phone_verified_at' => $user->phone_verified_at,
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
                'identity_document_original_name',
                'identity_document_uploaded_at',
                'identity_document_size_bytes',
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

    private function nullIfBlank(mixed $value): ?string
    {
        $str = trim((string) ($value ?? ''));

        return $str === '' ? null : $str;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditProfileSnapshot(User $user): array
    {
        return [
            'user' => $user->only(['name', 'email', 'phone_primary', 'phone_secondary', 'applicant_type']),
            'applicant_profile' => $user->applicantProfile?->only([
                'first_name',
                'middle_name',
                'surname',
                'nrc_number',
                'passport_number',
                'email',
                'phone_primary',
                'phone_secondary',
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
                'email',
                'phone_primary',
                'phone_secondary',
                'address_line_1',
                'address_line_2',
                'city',
                'province',
                'postal_code',
                'country',
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string>
     */
    private function changedFields(array $before, array $after): array
    {
        $beforeFlat = $this->flattenForDiff($before);
        $afterFlat = $this->flattenForDiff($after);

        $keys = array_unique(array_merge(array_keys($beforeFlat), array_keys($afterFlat)));
        sort($keys);

        $changed = [];
        foreach ($keys as $key) {
            $b = $beforeFlat[$key] ?? null;
            $a = $afterFlat[$key] ?? null;
            if ($b !== $a) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, scalar|null>
     */
    private function flattenForDiff(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $out += $this->flattenForDiff($value, $path);
                continue;
            }

            $out[$path] = is_scalar($value) || $value === null ? $value : json_encode($value);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function changeTrailPayload(User $user): array
    {
        return AuditLog::query()
            ->where('actor_user_id', $user->id)
            ->whereIn('event_type', [
                'applicants.profile_updated',
                'applicants.profile_identity_document_uploaded',
                'applicants.profile_identity_document_removed',
            ])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'event_type', 'message', 'metadata', 'created_at'])
            ->map(function (AuditLog $log) {
                $changedFields = data_get($log->metadata, 'changed_fields');
                $changedFields = is_array($changedFields) ? array_values($changedFields) : [];

                return [
                    'id' => (int) $log->id,
                    'event_type' => (string) $log->event_type,
                    'message' => (string) $log->message,
                    'created_at' => optional($log->created_at)->toIso8601String(),
                    'changed_fields' => $changedFields,
                ];
            })
            ->values()
            ->all();
    }
}
