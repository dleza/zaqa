<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Applicant\ApplicantQualificationsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantQualificationsController extends Controller
{
    public function index(Request $request, ApplicantQualificationsService $qualifications): Response
    {
        $user = $request->user();
        $filter = $qualifications->normalizeFilter((string) $request->query('filter', ApplicantQualificationsService::FILTER_TOTAL));

        return Inertia::render('Applicant/Qualifications/Index', [
            'filter' => $filter,
            'filterLabel' => $qualifications->filterLabel($filter),
            'qualifications' => $qualifications->listFor($user, $filter),
            'counts' => $qualifications->countsFor($user),
        ]);
    }
}
