<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UpdateApplicantPasswordRequest;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Qualification;
use App\Models\QualificationAssignment;
use App\Models\User;
use App\Models\VerificationAssignmentCategoryUser;
use App\Enums\VerificationState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $user->loadMissing(['department', 'roles']);

        $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->values()->all() : [];
        $primaryRole = $roles[0] ?? null;

        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'is_active' => (bool) $d->is_active])
            ->values();

        $recentActivity = AuditLog::query()
            ->where('actor_user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'module', 'message', 'entity_type', 'entity_id', 'created_at'])
            ->map(function (AuditLog $log) {
                return [
                    'id' => (int) $log->id,
                    'module' => (string) $log->module,
                    'message' => (string) $log->message,
                    'created_at' => optional($log->created_at)->toIso8601String(),
                    'url' => $this->activityUrlFor((string) ($log->entity_type ?? ''), $log->entity_id ? (int) $log->entity_id : null),
                ];
            })
            ->values();

        $stats = $this->buildStats($user);
        $memberships = $this->buildLevel1Memberships($user);

        return Inertia::render('Admin/Profile/Show', [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone_primary' => $user->phone_primary,
                'phone_secondary' => $user->phone_secondary,
                'department' => $user->department ? ['id' => $user->department->id, 'name' => $user->department->name] : null,
                'roles' => $roles,
                'primary_role' => $primaryRole,
                'is_active' => (bool) $user->is_active,
                'profile_photo_url' => $user->profile_photo_url,
                'last_login_at' => optional($user->last_login_at)?->toIso8601String(),
                'created_at' => optional($user->created_at)?->toIso8601String(),
            ],
            'departments' => $departments,
            'recent_activity' => $recentActivity,
            'stats' => $stats,
            'level1_memberships' => $memberships,
        ]);
    }

    public function editPassword(): Response
    {
        return Inertia::render('Admin/Profile/ChangePassword');
    }

    public function update(Request $request, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'phone_primary' => ['nullable', 'string', 'max:50', Rule::unique('users', 'phone_primary')->ignore($user->id)],
            'phone_secondary' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ]);

        $before = [
            'phone_primary' => $user->phone_primary,
            'phone_secondary' => $user->phone_secondary,
            'department_id' => $user->department_id,
        ];

        $user->forceFill([
            'phone_primary' => array_key_exists('phone_primary', $validated) ? ($validated['phone_primary'] ? (string) $validated['phone_primary'] : null) : $user->phone_primary,
            'phone_secondary' => array_key_exists('phone_secondary', $validated) ? ($validated['phone_secondary'] ? (string) $validated['phone_secondary'] : null) : $user->phone_secondary,
            'department_id' => array_key_exists('department_id', $validated) ? ($validated['department_id'] ? (int) $validated['department_id'] : null) : $user->department_id,
        ])->save();

        $audit->record(
            eventType: 'admin.profile_updated',
            module: 'Account',
            actionName: 'profile_updated',
            message: 'Admin updated their profile details.',
            entityType: User::class,
            entityId: $user->id,
            beforeState: $before,
            afterState: [
                'phone_primary' => $user->phone_primary,
                'phone_secondary' => $user->phone_secondary,
                'department_id' => $user->department_id,
            ],
            actor: $user,
        );

        return redirect('/admin/profile')->with('success', 'Profile updated.');
    }

    public function storePhoto(Request $request, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'photo' => [
                'required',
                'file',
                'max:2048', // KB (2MB)
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
        ]);

        $file = $request->file('photo');
        if (! $file) {
            return back()->withErrors(['photo' => 'Please choose a photo to upload.']);
        }

        DB::transaction(function () use ($user, $file, $audit, $validated) {
            $before = [
                'profile_photo_path' => $user->profile_photo_path,
            ];

            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }

            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $storedName = sprintf('profile_%s_%s.%s', now()->format('YmdHis'), Str::random(8), $extension);
            $directory = sprintf('admin-profiles/%s/profile-photo', $user->id);
            $path = $file->storeAs($directory, $storedName, ['disk' => 'public']);

            $user->forceFill([
                'profile_photo_path' => $path,
            ])->save();

            $audit->record(
                eventType: 'admin.profile_photo_updated',
                module: 'Account',
                actionName: 'profile_photo_updated',
                message: 'Admin updated their profile photo.',
                entityType: User::class,
                entityId: $user->id,
                beforeState: $before,
                afterState: [
                    'profile_photo_path' => $user->profile_photo_path,
                ],
                metadata: [
                    'mime' => $file->getClientMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                ],
                actor: $user,
            );
        });

        return redirect('/admin/profile')->with('success', 'Profile photo updated.');
    }

    public function destroyPhoto(Request $request, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! $user->profile_photo_path) {
            return redirect('/admin/profile');
        }

        DB::transaction(function () use ($user, $audit) {
            $before = [
                'profile_photo_path' => $user->profile_photo_path,
            ];

            Storage::disk('public')->delete($user->profile_photo_path);

            $user->forceFill(['profile_photo_path' => null])->save();

            $audit->record(
                eventType: 'admin.profile_photo_removed',
                module: 'Account',
                actionName: 'profile_photo_removed',
                message: 'Admin removed their profile photo.',
                entityType: User::class,
                entityId: $user->id,
                beforeState: $before,
                afterState: [
                    'profile_photo_path' => null,
                ],
                actor: $user,
            );
        });

        return redirect('/admin/profile')->with('success', 'Profile photo removed.');
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

    private function activityUrlFor(string $entityType, ?int $entityId): ?string
    {
        if (! $entityId) {
            return null;
        }

        return match ($entityType) {
            \App\Models\Qualification::class => route('admin.verification.qualifications.show', ['qualification' => $entityId]),
            \App\Models\Application::class => route('admin.verification.applications.show', ['application' => $entityId]),
            \App\Models\Payment::class => route('admin.finance.payments.show', ['payment' => $entityId]),
            \App\Models\InstitutionApiClient::class => '/admin/integrations/institution-api-clients/'.$entityId,
            \App\Models\AwardingInstitution::class => '/admin/settings/awarding-institutions/'.$entityId,
            \App\Models\LearnerRecordImport::class => '/admin/learner-records/imports/'.$entityId,
            default => null,
        };
    }

    private function buildStats(User $user): array
    {
        $isLevel1 = $user->hasRole('Verification Officer Level 1');
        $isLevel2 = $user->hasRole('Verification Officer Level 2');
        $isSuper = $user->hasRole('Super Admin');

        $stats = [
            'role' => $isSuper ? 'super_admin' : ($isLevel2 ? 'level2' : ($isLevel1 ? 'level1' : 'staff')),
            'cards' => [],
        ];

        if ($isLevel1) {
            $pending = Qualification::query()
                ->where('assigned_verifier_id', $user->id)
                ->whereIn('verification_state', [
                    VerificationState::AssignedToLevel1->value,
                    VerificationState::UnderLevel1Review->value,
                ])
                ->count();

            $completedToL2 = Qualification::query()
                ->where('assigned_verifier_id', $user->id)
                ->where('verification_state', VerificationState::UnderLevel2Review->value)
                ->count();

            $reviewedTotal = Qualification::query()
                ->where('assigned_verifier_id', $user->id)
                ->whereNotNull('reviewed_at')
                ->count();

            $avgSeconds = Qualification::query()
                ->where('assigned_verifier_id', $user->id)
                ->whereNotNull('assigned_at')
                ->whereNotNull('reviewed_at')
                ->selectRaw('avg(timestampdiff(second, assigned_at, reviewed_at)) as avg_s')
                ->value('avg_s');

            $avgMinutes = $avgSeconds !== null ? (int) round(((float) $avgSeconds) / 60) : null;

            $categoryCount = VerificationAssignmentCategoryUser::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->count();

            $stats['cards'] = [
                ['label' => 'Pending reviews', 'value' => $pending],
                ['label' => 'Sent to Level 2', 'value' => $completedToL2],
                ['label' => 'Reviewed (total)', 'value' => $reviewedTotal],
                ['label' => 'Avg turnaround (mins)', 'value' => $avgMinutes ?? '—'],
                ['label' => 'Assigned categories', 'value' => $categoryCount],
            ];
        } elseif ($isLevel2) {
            $pendingOwned = Qualification::query()
                ->where('verification_state', VerificationState::UnderLevel2Review->value)
                ->where('level2_review_owner_id', $user->id)
                ->count();

            $autoVerifiedPending = Qualification::query()
                ->where('verification_state', VerificationState::AutoVerifiedPendingLevel2->value)
                ->count();

            $locks = Qualification::query()
                ->where('verification_state', VerificationState::AutoVerifiedPendingLevel2->value)
                ->where('level2_review_locked_by', $user->id)
                ->count();

            $assignmentsMade = QualificationAssignment::query()
                ->where('assigned_by_user_id', $user->id)
                ->count();

            $stats['cards'] = [
                ['label' => 'Pending Level 2 (owned)', 'value' => $pendingOwned],
                ['label' => 'Auto-verified pending', 'value' => $autoVerifiedPending],
                ['label' => 'My active locks', 'value' => $locks],
                ['label' => 'Assignments made', 'value' => $assignmentsMade],
            ];
        } elseif ($isSuper) {
            $stats['cards'] = [
                ['label' => 'Users', 'value' => \App\Models\User::query()->count()],
                ['label' => 'Active institutions', 'value' => \App\Models\AwardingInstitution::query()->where('is_active', true)->count()],
                ['label' => 'Pending Level 2', 'value' => Qualification::query()->where('verification_state', VerificationState::UnderLevel2Review->value)->count()],
                ['label' => 'Auto-verified pending', 'value' => Qualification::query()->where('verification_state', VerificationState::AutoVerifiedPendingLevel2->value)->count()],
            ];
        }

        return $stats;
    }

    private function buildLevel1Memberships(User $user): array
    {
        if (! $user->hasRole('Verification Officer Level 1')) {
            return [];
        }

        return VerificationAssignmentCategoryUser::query()
            ->with([
                'category' => fn ($q) => $q->withCount(['countries', 'awardingInstitutions']),
            ])
            ->where('user_id', $user->id)
            ->orderByDesc('is_active')
            ->get()
            ->map(function (VerificationAssignmentCategoryUser $m) {
                $c = $m->category;

                return [
                    'id' => (int) $m->id,
                    'category' => $c ? [
                        'id' => (int) $c->id,
                        'name' => (string) $c->name,
                        'type' => (string) $c->type,
                        'mapped_count' => (string) $c->type === 'foreign_country'
                            ? (int) ($c->countries_count ?? 0)
                            : (int) ($c->awarding_institutions_count ?? 0),
                        'url' => route('admin.verification.assignment_categories.show', ['assignmentCategory' => $c->id]),
                    ] : null,
                    'is_active' => (bool) $m->is_active,
                    'is_available' => (bool) $m->is_available,
                    'unavailable_reason' => $m->unavailable_reason,
                    'unavailable_until' => optional($m->unavailable_until)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
