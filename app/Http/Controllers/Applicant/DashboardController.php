<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Applicant\ApplicantDashboardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, ApplicantDashboardService $dashboard): Response
    {
        $user = $request->user();
        $payload = $dashboard->build($user);

        return Inertia::render('Applicant/Dashboard', [
            'counts' => $payload['counts'],
            'continueDraft' => $payload['continue_draft'],
            'applications' => $payload['applications'],
            'activity' => $payload['activity'],
            'alerts' => $payload['alerts'],
        ]);
    }
}

