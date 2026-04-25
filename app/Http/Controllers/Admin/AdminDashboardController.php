<?php

namespace App\Http\Controllers\Admin;

use App\Domain\AdminDashboard\AdminDashboardService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function index(Request $request, AdminDashboardService $dashboard): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Admin/Dashboard', $dashboard->build($user));
    }
}
