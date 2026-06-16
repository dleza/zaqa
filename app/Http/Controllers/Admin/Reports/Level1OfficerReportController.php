<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Domain\Reports\Level1OfficerReportService;
use App\Domain\Verification\VerificationQualificationAccess;
use App\Http\Controllers\Controller;
use App\Support\Reports\ReportDateRange;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class Level1OfficerReportController extends Controller
{
    public function index(Request $request, Level1OfficerReportService $service): Response
    {
        $user = $request->user();
        abort_unless($user && VerificationQualificationAccess::mustRestrictToAssignedQualifications($user), 403);

        $dr = ReportDateRange::fromRequest($request);
        $dashboard = $service->dashboard($user, $dr['from'], $dr['to']);

        return Inertia::render('Admin/Reports/Level1Performance', [
            'filters' => [
                'range' => $dr['range'],
                'from' => $dr['from']->toDateString(),
                'to' => $dr['to']->toDateString(),
            ],
            'dashboard' => $dashboard,
        ]);
    }
}
