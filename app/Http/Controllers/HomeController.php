<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->is_active) {
            return redirect('/applicant/dashboard');
        }

        if ($user && ! $user->is_active) {
            return redirect('/activate');
        }

        return Inertia::render('Home', [
            'canLogin' => true,
        ]);
    }
}

