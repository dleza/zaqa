<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Verification\ApplicationsPoolService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminVerificationAssignedToMeController extends Controller
{
    public function index(Request $request, ApplicationsPoolService $pool): Response
    {
        $request->merge(['mine' => '1']);

        $apps = $pool->pool($request, $request->user()?->id);

        return Inertia::render('Admin/Verification/AssignedToMe', [
            'applications' => $apps->through(fn ($a) => [
                'id' => $a->id,
                'application_number' => $a->application_number,
                'current_status' => $a->current_status?->value ?? (string) $a->current_status,
                'verification_state' => $a->verification_state?->value ?? null,
                'service_deadline_at' => optional($a->service_deadline_at)?->toIso8601String(),
                'updated_at' => optional($a->updated_at)?->toIso8601String(),
                'applicant_name' => $a->metadata['verification_subject']['full_name'] ?? $a->applicant?->name,
            ]),
            'filters' => [
                'q' => (string) $request->query('q', ''),
                'overdue' => $request->query('overdue'),
                'overdue_days' => $request->query('overdue_days'),
                'submitted_from' => $request->query('submitted_from'),
                'submitted_to' => $request->query('submitted_to'),
                'qualification_q' => $request->query('qualification_q'),
            ],
        ]);
    }
}

