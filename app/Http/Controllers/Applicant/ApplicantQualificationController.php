<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Applications\QualificationCaptureService;
use App\Domain\Payments\ApplicationPaymentSatisfaction;
use App\Domain\Payments\InvoiceService;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UpsertQualificationDetailsRequest;
use App\Http\Requests\Applicant\UpsertQualificationRequest;
use App\Http\Requests\Applicant\UpsertSubjectResultsRequest;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApplicantQualificationController extends Controller
{
    public function store(UpsertQualificationRequest $request, Application $application, QualificationCaptureService $service, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('update', $application);

        $payload = array_merge($request->validated(), ['create_new' => true]);
        $qualification = $service->upsertQualification($application, $payload, $request->user());
        $this->syncInvoiceIfExists($application->fresh(), $request->user(), $invoices);

        return back()
            ->with('success', 'Qualification added.')
            ->with('created_qualification_id', $qualification->id);
    }

    public function upsertDetails(UpsertQualificationDetailsRequest $request, Application $application, QualificationCaptureService $service, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('update', $application);

        $service->upsertQualificationDetails($application, $request->validated(), $request->user());
        $this->syncInvoiceIfExists($application->fresh(), $request->user(), $invoices);

        return back()->with('success', 'Qualification details saved.');
    }

    public function upsertSubjectResults(UpsertSubjectResultsRequest $request, Application $application, QualificationCaptureService $service, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('update', $application);

        $payload = $request->validated();

        $service->upsertSubjectResults($application, $payload, $request->user());
        $this->syncInvoiceIfExists($application->fresh(), $request->user(), $invoices);

        return back()->with('success', 'Subject results saved.');
    }

    public function upsert(UpsertQualificationRequest $request, Application $application, QualificationCaptureService $service, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('update', $application);

        $service->upsertQualification($application, $request->validated(), $request->user());
        $this->syncInvoiceIfExists($application->fresh(), $request->user(), $invoices);

        return back()->with('success', 'Qualification details saved.');
    }

    public function finalizeAmendment(
        Request $request,
        Application $application,
        Qualification $qualification,
        QualificationCaptureService $capture,
        ApplicationPaymentSatisfaction $paymentSatisfaction,
    ): RedirectResponse {
        $this->authorize('update', $application);

        if ((int) $qualification->application_id !== (int) $application->id) {
            abort(404);
        }

        if ($qualification->verification_state !== VerificationState::ReturnedToApplicant) {
            throw ValidationException::withMessages([
                'qualification' => 'This qualification is not waiting for you to submit corrections.',
            ]);
        }

        $application->refresh()->loadMissing('qualifications', 'payments', 'invoice');
        if (! $paymentSatisfaction->isSatisfied($application)) {
            throw ValidationException::withMessages([
                'payment' => 'Pay the outstanding balance for this application (Payment step) before you send your corrections back to ZAQA.',
            ]);
        }

        $capture->reopenQualificationAfterApplicantAmendment($qualification->fresh(), $request->user());

        return back()->with('success', 'Your corrections have been sent back to ZAQA for review.');
    }

    public function destroy(Request $request, Application $application, Qualification $qualification, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('update', $application);
        if ($qualification->application_id !== $application->id) {
            abort(404);
        }

        if ($this->isApplicationPaymentSettled($application)) {
            return back()->withErrors(['application' => 'This application cannot be edited after payment has been confirmed.']);
        }

        Qualification::destroy((int) $qualification->id);

        // Keep aggregate locality correct.
        $application->refresh()->loadMissing('qualifications');
        $application->forceFill([
            'is_foreign' => (bool) $application->qualifications->contains(fn (Qualification $q) => (bool) $q->is_foreign_qualification),
        ])->save();

        $this->syncInvoiceIfExists($application->fresh(), $request->user(), $invoices);

        return back()->with('success', 'Qualification removed.');
    }

    private function syncInvoiceIfExists(Application $application, User $actor, InvoiceService $invoices): void
    {
        if (! Invoice::query()->where('application_id', $application->id)->exists()) {
            return;
        }

        $invoices->ensureInvoice($application->fresh(), $actor);
    }

    private function isApplicationPaymentSettled(Application $application): bool
    {
        if ($application->paid_at) {
            return true;
        }

        $application->loadMissing('invoice', 'payments');

        if ($application->invoice?->status === InvoiceStatus::Paid) {
            return true;
        }

        return $application->payments->contains(fn (Payment $p) => $p->status === PaymentStatus::Confirmed);
    }
}
