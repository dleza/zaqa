<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Feedback\ServiceFeedbackService;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\StoreServiceFeedbackRequest;
use App\Models\Application;
use App\Models\ServiceFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantServiceFeedbackController extends Controller
{
    public function show(Request $request, Application $application, ServiceFeedbackService $service): Response
    {
        $this->authorize('view', $application);

        $application->loadMissing(['invoice', 'payments', 'serviceFeedback']);

        if (! $application->serviceFeedback) {
            $service->recordPromptShown($application, $request->user());
        }

        $paymentsSorted = $application->payments->sortByDesc('id');
        $displayPayment = $paymentsSorted->first(fn ($p) => $p->status === PaymentStatus::Confirmed)
            ?? $paymentsSorted->first();

        return Inertia::render('Applicant/Applications/Feedback', [
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'status_label' => $application->applicantStatusLabel(),
                'invoice' => $application->invoice
                    ? [
                        'invoice_number' => $application->invoice->invoice_number,
                        'amount_cents' => $application->invoice->amount_cents,
                        'currency' => $application->invoice->currency,
                        'status' => $application->invoice->status?->value ?? (string) $application->invoice->status,
                    ]
                    : null,
                'payment' => $displayPayment
                    ? [
                        'method' => $displayPayment->method?->value ?? (string) $displayPayment->method,
                        'status' => $displayPayment->status?->value ?? (string) $displayPayment->status,
                        'confirmed_at' => optional($displayPayment->confirmed_at)?->toIso8601String(),
                    ]
                    : null,
            ],
            'existingFeedback' => $application->serviceFeedback
                ? [
                    'id' => $application->serviceFeedback->id,
                    'rating_value' => $application->serviceFeedback->rating_value,
                    'rating_label' => $application->serviceFeedback->rating_label,
                    'feedback_text' => $application->serviceFeedback->feedback_text,
                    'submitted_at' => optional($application->serviceFeedback->submitted_at)?->toIso8601String(),
                  ]
                : null,
        ]);
    }

    public function store(StoreServiceFeedbackRequest $request, Application $application, ServiceFeedbackService $service): RedirectResponse
    {
        $this->authorize('view', $application);

        $validated = $request->validated();

        $service->submit($application, $request->user(), [
            'rating_value' => (int) $validated['rating_value'],
            'rating_label' => $validated['rating_label'] ?? null,
            'feedback_text' => $validated['feedback_text'] ?? null,
            'source' => 'applicant_submission_flow',
            'source_step' => 'review_and_submit',
            'metadata' => [
                'user_agent' => (string) ($request->userAgent() ?? ''),
            ],
        ]);

        return redirect()->route('applicant.applications.show', $application)
            ->with('success', 'Thank you. Your feedback has been submitted.');
    }

    public function skip(Request $request, Application $application, ServiceFeedbackService $service): RedirectResponse
    {
        $this->authorize('view', $application);

        $service->recordSkipped($application, $request->user());

        return redirect()->route('applicant.applications.show', $application)
            ->with('success', 'Thank you. You can provide feedback later from your application.');
    }
}
