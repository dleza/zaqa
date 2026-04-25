<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Applications\QualificationCaptureService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UpsertQualificationDetailsRequest;
use App\Http\Requests\Applicant\UpsertQualificationRequest;
use App\Http\Requests\Applicant\UpsertSubjectResultsRequest;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;

class ApplicantQualificationController extends Controller
{
    public function upsertDetails(UpsertQualificationDetailsRequest $request, Application $application, QualificationCaptureService $service): RedirectResponse
    {
        $this->authorize('update', $application);

        $service->upsertQualificationDetails($application, $request->validated(), $request->user());

        return back()->with('success', 'Qualification details saved.');
    }

    public function upsertSubjectResults(UpsertSubjectResultsRequest $request, Application $application, QualificationCaptureService $service): RedirectResponse
    {
        $this->authorize('update', $application);

        /** @var array<int, array<string, mixed>> $subjectResults */
        $subjectResults = $request->validated()['subject_results'];

        $service->upsertSubjectResults($application, $subjectResults, $request->user());

        return back()->with('success', 'Subject results saved.');
    }

    public function upsert(UpsertQualificationRequest $request, Application $application, QualificationCaptureService $service): RedirectResponse
    {
        $this->authorize('update', $application);

        $service->upsertQualification($application, $request->validated(), $request->user());

        return back()->with('success', 'Qualification details saved.');
    }
}

