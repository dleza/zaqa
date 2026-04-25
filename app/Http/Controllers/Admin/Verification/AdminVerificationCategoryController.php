<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Verification\ApplicationsPoolService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminVerificationCategoryController extends Controller
{
    public function byCountry(Request $request, ApplicationsPoolService $pool): Response
    {
        $filters = [
            'overdue_days' => $request->query('overdue_days'),
            'submitted_from' => $request->query('submitted_from'),
            'submitted_to' => $request->query('submitted_to'),
            'qualification_q' => $request->query('qualification_q'),
        ];

        return Inertia::render('Admin/Verification/Pool/ByCountry', [
            'groups' => $pool->byCountryCounts($request->query('hide_zambia') === '1', $filters),
            'filters' => [
                'hide_zambia' => $request->query('hide_zambia') === '1' ? '1' : null,
                'overdue_days' => $request->query('overdue_days'),
                'submitted_from' => $request->query('submitted_from'),
                'submitted_to' => $request->query('submitted_to'),
                'qualification_q' => $request->query('qualification_q'),
            ],
        ]);
    }

    public function byAwardingBody(Request $request, ApplicationsPoolService $pool): Response
    {
        return Inertia::render('Admin/Verification/Pool/ByAwardingBody', [
            'groups' => $pool->byAwardingInstitutionCounts(),
        ]);
    }

    public function byAwardingInstitution(Request $request, ApplicationsPoolService $pool): Response
    {
        $filters = [
            'overdue_days' => $request->query('overdue_days'),
            'submitted_from' => $request->query('submitted_from'),
            'submitted_to' => $request->query('submitted_to'),
            'qualification_q' => $request->query('qualification_q'),
        ];

        return Inertia::render('Admin/Verification/Pool/ByAwardingInstitution', [
            'groups' => $pool->byAwardingInstitutionCounts($filters),
            'filters' => $filters,
        ]);
    }
}

