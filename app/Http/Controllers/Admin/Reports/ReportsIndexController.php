<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Support\Reports\ReportAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportsIndexController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        ReportAuthorization::abortUnlessAny($request->user());

        $categories = ReportAuthorization::indexCategories($request->user());

        if (count($categories) === 1) {
            return redirect($categories[0]['href']);
        }

        return Inertia::render('Admin/Reports/Index', [
            'categories' => $categories,
        ]);
    }
}
