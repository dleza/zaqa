<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Applications\ApplicantQualificationAmendmentGuard;
use App\Domain\Applications\QualificationCaptureService;
use App\Domain\Payments\InvoiceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UpsertInstitutionalQualificationRequest;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Qualification;
use App\Models\User;
use App\Support\Applications\ApplicationSubmissionMode;
use Illuminate\Http\RedirectResponse;

class ApplicantInstitutionalQualificationController extends Controller
{
    public function store(
        UpsertInstitutionalQualificationRequest $request,
        Application $application,
        QualificationCaptureService $service,
        InvoiceService $invoices,
    ): RedirectResponse {
        $this->authorize('update', $application);
        $this->assertInstitutionalMultiple($application);
        ApplicantQualificationAmendmentGuard::assertCanCreateQualification($application);

        $payload = array_merge($request->validated(), ['create_new' => true]);
        $qualification = $service->upsertQualification($application, $payload, $request->user());
        $this->syncInvoiceIfExists($application->fresh(), $request->user(), $invoices);

        return redirect()
            ->route('applicant.applications.multiple.qualifications.edit', [
                'application' => $application->id,
                'qualification' => $qualification->id,
            ])
            ->with('success', 'Qualification record added.');
    }

    public function upsert(
        UpsertInstitutionalQualificationRequest $request,
        Application $application,
        Qualification $qualification,
        QualificationCaptureService $service,
        InvoiceService $invoices,
    ): RedirectResponse {
        $this->authorize('update', $application);
        $this->assertInstitutionalMultiple($application);

        if ((int) $qualification->application_id !== (int) $application->id) {
            abort(404);
        }

        ApplicantQualificationAmendmentGuard::assertQualificationEditable($application, $qualification);

        $payload = array_merge($request->validated(), [
            'qualification_id' => $qualification->id,
            'create_new' => false,
        ]);
        $service->upsertQualification($application, $payload, $request->user());
        $this->syncInvoiceIfExists($application->fresh(), $request->user(), $invoices);

        return back()->with('success', 'Qualification record saved.');
    }

    public function destroy(
        Application $application,
        Qualification $qualification,
        InvoiceService $invoices,
    ): RedirectResponse {
        $this->authorize('update', $application);
        $this->assertInstitutionalMultiple($application);

        if ((int) $qualification->application_id !== (int) $application->id) {
            abort(404);
        }

        ApplicantQualificationAmendmentGuard::assertQualificationEditable($application, $qualification);
        $qualification->delete();
        $this->syncInvoiceIfExists($application->fresh(), request()->user(), $invoices);

        return redirect()
            ->route('applicant.applications.multiple.edit', [
                'application' => $application->id,
                'step' => 'qualification_records',
            ])
            ->with('success', 'Qualification record removed.');
    }

    private function assertInstitutionalMultiple(Application $application): void
    {
        if (! ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
            abort(404);
        }
    }

    private function syncInvoiceIfExists(Application $application, User $actor, InvoiceService $invoices): void
    {
        if (! Invoice::query()->where('application_id', $application->id)->exists()) {
            return;
        }

        $invoices->ensureInvoice($application->fresh(), $actor);
    }
}
