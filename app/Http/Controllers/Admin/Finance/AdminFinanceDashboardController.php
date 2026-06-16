<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Domain\AdminDashboard\DashboardDateRange;
use App\Domain\Finance\FinanceDashboardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminFinanceDashboardController extends Controller
{
    public function index(Request $request, FinanceDashboardService $finance): Response
    {
        return Inertia::render('Admin/Finance/Dashboard', $finance->build(DashboardDateRange::fromRequest($request)));
    }
}
