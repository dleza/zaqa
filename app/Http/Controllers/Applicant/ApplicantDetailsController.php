<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Applicants\ApplicantDetailsService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UpdateApplicantDetailsRequest;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;

class ApplicantDetailsController extends Controller
{
    public function update(
        UpdateApplicantDetailsRequest $request,
        Application $application,
        ApplicantDetailsService $service,
        ApplicationLifecycleService $lifecycle,
    ): RedirectResponse
    {
        $this->authorize('update', $application);

        $service->update($request->user(), $request->validated(), $request->user());

        $lifecycle->milestone(
            application: $application,
            eventType: 'wizard',
            eventCode: 'wizard.step1.applicant_saved',
            stage: LifecycleStage::Wizard,
            title: 'Applicant details saved',
            description: 'Applicant saved contact and identity details.',
            visibility: LifecycleVisibility::Both,
            actor: $request->user(),
            metadata: [
                'fields' => array_keys($request->validated()),
            ],
            occurredAt: now(),
        );

        return back()->with('success', 'Applicant details saved.');
    }
}

