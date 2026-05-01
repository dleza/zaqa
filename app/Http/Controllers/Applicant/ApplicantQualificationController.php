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
    public function store(UpsertQualificationRequest $request, Application $application, QualificationCaptureService $service): RedirectResponse
    {
        $this->authorize('update', $application);

        $payload = array_merge($request->validated(), ['create_new' => true]);
        $service->upsertQualification($application, $payload, $request->user());

        return back()->with('success', 'Qualification added.');
    }

    public function upsertDetails(UpsertQualificationDetailsRequest $request, Application $application, QualificationCaptureService $service): RedirectResponse
    {
        $this->authorize('update', $application);

        $service->upsertQualificationDetails($application, $request->validated(), $request->user());

        return back()->with('success', 'Qualification details saved.');
    }

    public function upsertSubjectResults(UpsertSubjectResultsRequest $request, Application $application, QualificationCaptureService $service): RedirectResponse
    {
        $this->authorize('update', $application);

        $payload = $request->validated();

        $service->upsertSubjectResults($application, $payload, $request->user());

        return back()->with('success', 'Subject results saved.');
    }

    public function upsert(UpsertQualificationRequest $request, Application $application, QualificationCaptureService $service): RedirectResponse
    {
        $this->authorize('update', $application);

        $service->upsertQualification($application, $request->validated(), $request->user());

        return back()->with('success', 'Qualification details saved.');
    }

    public function destroy(\Illuminate\Http\Request $request, Application $application, \App\Models\Qualification $qualification): RedirectResponse
    {
        $this->authorize('update', $application);
        if ($qualification->application_id !== $application->id) {
            abort(404);
        }

        if ($application->paid_at) {
            return back()->withErrors(['application' => 'Paid applications cannot be edited.']);
        }

        \App\Models\Qualification::destroy((int) $qualification->id);

        // Keep aggregate locality correct.
        $application->refresh()->loadMissing('qualifications');
        $application->forceFill([
            'is_foreign' => (bool) $application->qualifications->contains(fn (\App\Models\Qualification $q) => (bool) $q->is_foreign_qualification),
        ])->save();

        return back()->with('success', 'Qualification removed.');
    }
}

