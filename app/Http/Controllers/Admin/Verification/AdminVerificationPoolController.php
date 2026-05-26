<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Verification\QualificationsPoolService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminVerificationPoolController extends Controller
{
    public function index(Request $request, QualificationsPoolService $pool): Response
    {
        $rows = $pool->pool($request, $request->user()?->id);

        return Inertia::render('Admin/Verification/Pool/Index', [
            'qualifications' => $rows->through(fn ($q) => [
                'id' => $q->id,
                'verification_state' => $q->verification_state,
                'is_foreign' => (bool) $q->is_foreign_qualification,
                'assigned_verifier' => $q->assignedVerifier?->name,
                'assigned_verifier_id' => $q->assigned_verifier_id,
                'assignment_source' => $q->assignment_source,
                'assignment_failure_reason' => $q->assignment_failure_reason,
                'updated_at' => optional($q->updated_at)?->toIso8601String(),
                'application' => [
                    'id' => $q->application?->id,
                    'application_number' => $q->application?->application_number,
                    'current_status' => $q->application?->current_status?->value ?? (string) $q->application?->current_status,
                    'payment_status' => $q->application?->paid_at ? 'paid' : 'unpaid',
                    'submitted_at' => optional($q->application?->submitted_at)?->toIso8601String(),
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
                'qualification_title' => $q->title_of_qualification,
                'qualification_type' => $q->qualificationTypeMaster?->name,
                'country_of_award' => $q->country?->name ?? $q->country_name_other,
                'awarding_institution' => $q->awardingInstitution?->name ?? $q->awarding_institution_name_other ?? $q->awarding_institution_name,
            ]),
            'filters' => [
                'q' => (string) $request->query('q', ''),
                'assigned' => $request->query('assigned'),
                'mine' => $request->query('mine'),
                'foreign' => $request->query('foreign'),
                'qualification_type_id' => $request->query('qualification_type_id'),
                'assigned_verifier_id' => $request->query('assigned_verifier_id'),
                'verification_state' => $request->query('verification_state'),
                'payment_status' => $request->query('payment_status'),
                'awarding_institution_id' => $request->query('awarding_institution_id'),
                'country_id' => $request->query('country_id'),
                'submitted_from' => $request->query('submitted_from'),
                'submitted_to' => $request->query('submitted_to'),
            ],
            'can' => [
                'assign' => (bool) $request->user()?->can('verification.assign'),
            ],
        ]);
    }
}
