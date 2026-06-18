<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class HowToApplyController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/HowToApply');
    }
}
