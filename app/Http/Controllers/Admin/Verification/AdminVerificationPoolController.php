<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Verification\ApplicationsPoolService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminVerificationPoolController extends Controller
{
    public function index(Request $request, ApplicationsPoolService $pool): Response
    {
        $fees = $pool->pool($request, $request->user()?->id);

        return Inertia::render('Admin/Verification/Pool/Index', [
            'applications' => $fees->through(fn ($a) => [
                'id' => $a->id,
                'application_number' => $a->application_number,
                'current_status' => $a->current_status?->value ?? (string) $a->current_status,
                'verification_state' => $a->verification_state?->value ?? null,
                'is_foreign' => (bool) $a->is_foreign,
                'service_deadline_at' => optional($a->service_deadline_at)?->toIso8601String(),
                'assigned_level1_user_id' => $a->assigned_level1_user_id,
                'updated_at' => optional($a->updated_at)?->toIso8601String(),
                'applicant_name' => $a->metadata['verification_subject']['full_name'] ?? $a->applicant?->name,
                'qualification_title' => $a->qualification?->title_of_qualification,
                'country_of_award' => $a->qualification?->country?->name,
                'awarding_institution' => $a->qualification?->awardingInstitution?->name ?? $a->qualification?->awarding_institution_name_other,
            ]),
            'filters' => [
                'q' => (string) $request->query('q', ''),
                'assigned' => $request->query('assigned'),
                'mine' => $request->query('mine'),
                'overdue' => $request->query('overdue'),
                'overdue_days' => $request->query('overdue_days'),
                'foreign' => $request->query('foreign'),
                // New canonical filter + legacy alias
                'awarding_institution_id' => $request->query('awarding_institution_id') ?? $request->query('awarding_body_id'),
                'country_id' => $request->query('country_id'),
                'submitted_from' => $request->query('submitted_from'),
                'submitted_to' => $request->query('submitted_to'),
                'qualification_q' => $request->query('qualification_q'),
            ],
            'can' => [
                'assign' => (bool) $request->user()?->can('verification.assign'),
            ],
        ]);
    }
}

