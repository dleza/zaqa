<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceFeedback;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminApplicantsController extends Controller
{
    public function index(Request $request): Response
    {
        $applicants = User::query()
            ->whereNotNull('applicant_type')
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone_primary' => $u->phone_primary,
                'applicant_type' => $u->applicant_type?->value,
                'is_active' => (bool) $u->is_active,
                'disabled_at' => optional($u->disabled_at)?->toIso8601String(),
                'created_at' => optional($u->created_at)?->toIso8601String(),
                'roles' => method_exists($u, 'getRoleNames') ? $u->getRoleNames()->values()->all() : [],
            ]);

        return Inertia::render('Admin/Applicants/Index', [
            'applicants' => $applicants,
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        if ($user->applicant_type === null) {
            abort(404);
        }

        $user->loadMissing(['applicantProfile', 'institutionProfile']);

        $totalApplications = Application::query()
            ->where('applicant_user_id', $user->id)
            ->count();

        $submittedCount = Application::query()
            ->where('applicant_user_id', $user->id)
            ->whereIn('current_status', ['submitted', 'resubmitted', 'in_progress', 'sent_back', 'approved', 'rejected', 'certificate_ready', 'completed'])
            ->count();

        $successCount = Application::query()
            ->where('applicant_user_id', $user->id)
            ->whereIn('current_status', ['approved', 'certificate_ready', 'completed'])
            ->count();

        $pendingCount = Application::query()
            ->where('applicant_user_id', $user->id)
            ->whereIn('current_status', ['draft', 'pending_payment', 'submitted', 'resubmitted', 'in_progress', 'sent_back'])
            ->count();

        $failedCount = Application::query()
            ->where('applicant_user_id', $user->id)
            ->whereIn('current_status', ['rejected'])
            ->count();

        $recentFeedback = ServiceFeedback::query()
            ->where('applicant_user_id', $user->id)
            ->latest('id')
            ->first();

        $recentApplications = Application::query()
            ->where('applicant_user_id', $user->id)
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (Application $a) => [
                'id' => $a->id,
                'application_number' => $a->application_number,
                'service_type' => $a->service_type?->value,
                'qualification_category' => $a->qualification_category,
                'is_foreign' => (bool) $a->is_foreign,
                'current_status' => $a->current_status?->value,
                'status_label' => $a->applicantStatusLabel(),
                'created_at' => optional($a->created_at)?->toIso8601String(),
                'submitted_at' => optional($a->submitted_at)?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Admin/Applicants/Show', [
            'applicant' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone_primary' => $user->phone_primary,
                'applicant_type' => $user->applicant_type?->value,
                'is_active' => (bool) $user->is_active,
                'disabled_at' => optional($user->disabled_at)?->toIso8601String(),
                'created_at' => optional($user->created_at)?->toIso8601String(),
                'roles' => method_exists($user, 'getRoleNames') ? $user->getRoleNames()->values()->all() : [],
                'profile' => $user->applicant_type?->value === 'individual'
                    ? [
                        'nrc_number' => $user->applicantProfile?->nrc_number,
                        'passport_number' => $user->applicantProfile?->passport_number,
                        'address_line1' => $user->applicantProfile?->address_line1,
                        'address_line2' => $user->applicantProfile?->address_line2,
                        'city' => $user->applicantProfile?->city,
                    ]
                    : [
                        'institution_name' => $user->institutionProfile?->institution_name,
                        'tpin' => $user->institutionProfile?->tpin,
                        'contact_person_name' => $user->institutionProfile?->contact_person_name,
                    ],
            ],
            'stats' => [
                'total' => $totalApplications,
                'submitted' => $submittedCount,
                'success' => $successCount,
                'pending' => $pendingCount,
                'failed' => $failedCount,
            ],
            'recent_feedback' => $recentFeedback
                ? [
                    'id' => $recentFeedback->id,
                    'application_id' => $recentFeedback->application_id,
                    'rating_value' => $recentFeedback->rating_value,
                    'rating_label' => $recentFeedback->rating_label,
                    'feedback_text' => $recentFeedback->feedback_text,
                    'source' => $recentFeedback->source,
                    'submitted_at' => optional($recentFeedback->submitted_at ?? $recentFeedback->created_at)?->toIso8601String(),
                ]
                : null,
            'recent_applications' => $recentApplications,
            'can_view_internal_application' => (bool) $request->user()?->can('admin.finance.view'),
        ]);
    }
}

