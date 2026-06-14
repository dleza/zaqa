<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Verification\QualificationsPoolService;
use App\Http\Controllers\Controller;
use App\Models\Qualification;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminVerificationAssignedToMeController extends Controller
{
    public function index(Request $request, QualificationsPoolService $pool): Response
    {
        $user = $request->user();
        if (! $user?->can('verification.level1.process') && ! $user?->can('verification.level2.review')) {
            abort(403);
        }

        $request->merge(['mine' => '1']);

        $rows = $pool->pool($request, $request->user()?->id);

        return Inertia::render('Admin/Verification/AssignedToMe', [
            'pageVariant' => 'assigned',
            'qualifications' => $rows->through(fn (Qualification $q) => [
                'id' => $q->id,
                'verification_state' => $q->verification_state?->value ?? (string) $q->verification_state,
                'qualification_title' => $q->title_of_qualification,
                'qualification_type' => $q->qualificationTypeMaster?->name,
                'service_deadline_at' => optional($q->service_deadline_at ?? $q->application?->service_deadline_at)?->toIso8601String(),
                'updated_at' => optional($q->updated_at)?->toIso8601String(),
                'application' => [
                    'id' => $q->application?->id,
                    'application_number' => $q->application?->application_number,
                    'current_status' => $q->application?->current_status?->value ?? (string) $q->application?->current_status,
                    'payment_status' => $q->application?->paid_at ? 'paid' : 'unpaid',
                    'submitted_at' => optional($q->application?->submitted_at)?->toIso8601String(),
                    'service_deadline_at' => optional($q->application?->service_deadline_at)?->toIso8601String(),
                ],
                'applicant_name' => $q->application?->metadata['verification_subject']['full_name'] ?? $q->application?->applicant?->name,
                'holder_name' => $q->qualification_holder_name ?: ($q->application?->metadata['verification_subject']['full_name'] ?? null),
                'holder_nrc_passport' => $q->nrc_passport_number ?: (function () use ($q) {
                    $subject = $q->application?->metadata['verification_subject'] ?? null;
                    if (! is_array($subject)) {
                        return null;
                    }

                    return ($subject['nrc_number'] ?? null) ?: ($subject['passport_number'] ?? null);
                })(),
                'country_of_award' => $q->country?->name ?? $q->country_name_other,
                'awarding_institution' => $q->awardingInstitution?->name ?? $q->awarding_institution_name_other ?? $q->awarding_institution_name,
                'is_foreign' => (bool) $q->is_foreign_qualification,
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
