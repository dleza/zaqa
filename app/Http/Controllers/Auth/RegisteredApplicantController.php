<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\ApplicantRegistrationService;
use App\Domain\Identity\Data\IndividualRegistrationData;
use App\Domain\Identity\Data\InstitutionRegistrationData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterIndividualRequest;
use App\Http\Requests\Auth\RegisterInstitutionRequest;
use App\Support\RegistrationOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredApplicantController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register', RegistrationOptions::inertiaProps());
    }

    public function storeIndividual(RegisterIndividualRequest $request, ApplicantRegistrationService $service): RedirectResponse
    {
        $user = $service->registerIndividual(IndividualRegistrationData::fromArray($request->validated()));

        Auth::login($user);

        if ($user->is_active) {
            return redirect('/applicant/dashboard')
                ->with('success', 'Welcome. Your account is ready.');
        }

        return redirect('/activate')
            ->with('success', 'Registration successful. Please activate your account to continue.');
    }

    public function storeInstitution(RegisterInstitutionRequest $request, ApplicantRegistrationService $service): RedirectResponse
    {
        $user = $service->registerInstitution(InstitutionRegistrationData::fromArray($request->validated()));

        Auth::login($user);

        if ($user->is_active) {
            return redirect('/applicant/dashboard')
                ->with('success', 'Welcome. Your account is ready.');
        }

        return redirect('/activate')
            ->with('success', 'Registration successful. Please activate your account to continue.');
    }
}

