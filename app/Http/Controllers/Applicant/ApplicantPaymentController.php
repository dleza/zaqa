<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Documents\ApplicantDocumentService;
use App\Domain\Payments\InvoiceService;
use App\Domain\Payments\PaymentService;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\InitiateMobileMoneyApplicationPaymentRequest;
use App\Http\Requests\Applicant\InitiateMobileMoneyPaymentRequest;
use App\Http\Requests\Applicant\SelectPaymentMethodRequest;
use App\Http\Requests\Applicant\UploadApplicationPaymentProofRequest;
use App\Http\Requests\Applicant\UploadPaymentProofRequest;
use App\Jobs\Payments\QueryCGratePaymentAttemptJob;
use App\Models\Application;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ApplicantPaymentController extends Controller
{
    public function prepare(Request $request, Application $application, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('update', $application);

        $invoices->ensureInvoice($application, $request->user());

        return back();
    }

    public function selectMethod(SelectPaymentMethodRequest $request, Application $application, PaymentService $payments): RedirectResponse
    {
        $this->authorize('update', $application);

        $method = PaymentMethod::from((string) $request->validated()['method']);
        $payments->rememberSelectedMethod($application, $method, $request->user());

        return back()->with('success', 'Payment method selected.');
    }

    public function initiateCard(Request $request, Payment $payment, PaymentService $payments): Response
    {
        $this->authorize('view', $payment->application);

        $result = $payments->initiateOnline($payment, [], $request->user());
        $redirectUrl = $result['redirect_url'] ?? null;

        if (! $redirectUrl) {
            return back()->with('error', 'Could not initiate card payment.');
        }

        if ($request->inertia()) {
            return Inertia::location($redirectUrl);
        }

        return redirect()->away($redirectUrl);
    }

    public function initiateCardForApplication(Request $request, Application $application, PaymentService $payments): Response
    {
        $this->authorize('view', $application);

        $payment = $payments->createDraftPayment($application, PaymentMethod::Card, $request->user());

        $result = $payments->initiateOnline($payment, [], $request->user());
        $redirectUrl = $result['redirect_url'] ?? null;
        if (! $redirectUrl) {
            return back()->with('error', 'Could not initiate card payment.');
        }

        if ($request->inertia()) {
            return Inertia::location($redirectUrl);
        }

        return redirect()->away($redirectUrl);
    }

    public function initiateMobileMoneyForApplication(
        InitiateMobileMoneyApplicationPaymentRequest $request,
        Application $application,
        PaymentService $payments,
    ): RedirectResponse {
        $this->authorize('view', $application);

        $payment = $payments->createDraftPayment($application, PaymentMethod::MobileMoney, $request->user());

        $payload = [
            'mobile_number' => (string) $request->validated()['mobile_number'],
        ];

        $payments->initiateOnline($payment, $payload, $request->user());

        return back()->with('success', 'Mobile Money payment initiated. Please approve the prompt on your phone.');
    }

    public function initiateMobileMoney(InitiateMobileMoneyPaymentRequest $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize('view', $payment->application);

        $payload = [
            'mobile_number' => (string) $request->validated()['mobile_number'],
        ];

        $payments->initiateOnline($payment, $payload, $request->user());

        return back()->with('success', 'Mobile Money payment initiated. Please approve the prompt on your phone.');
    }

    public function uploadProofForApplication(
        UploadApplicationPaymentProofRequest $request,
        Application $application,
        ApplicantDocumentService $documents,
        PaymentService $payments,
    ): RedirectResponse {
        $this->authorize('view', $application);

        $payment = $payments->paymentForManualProofUpload($application, $request->user());

        $file = $request->file('file');
        $document = $documents->upload($application, DocumentType::PaymentProof, $file, $request->user());

        $payments->attachProof($payment, $document, $request->user());

        return back()->with('success', 'Proof of payment uploaded. Awaiting finance review.');
    }

    public function uploadProof(UploadPaymentProofRequest $request, Payment $payment, ApplicantDocumentService $documents, PaymentService $payments): RedirectResponse
    {
        $this->authorize('view', $payment->application);

        $file = $request->file('file');

        $application = $payment->application()->firstOrFail();

        $document = $documents->upload($application, DocumentType::PaymentProof, $file, $request->user());

        $payments->attachProof($payment, $document, $request->user());

        return back()->with('success', 'Proof of payment uploaded. Awaiting finance review.');
    }

    public function returnFromProvider(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize('view', $payment->application);

        $payments->handleGatewayReturn($payment, $request->all());

        return redirect()->route('applicant.applications.edit', ['application' => $payment->application_id, 'step' => 'payment'])
            ->with('success', 'Payment status updated.');
    }

    /**
     * Test provider redirect simulation (dev/test driver).
     */
    public function testRedirect(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize('view', $payment->application);

        // Simulate provider redirect/return with success unless overridden.
        $status = (string) $request->query('status', 'success');
        $payload = [
            'status' => $status,
            'ref' => (string) $request->query('ref', $payment->provider_reference),
            'tx' => 'TX-'.now()->format('YmdHis'),
        ];

        $payments->handleGatewayReturn($payment, $payload);

        return redirect()->route('applicant.applications.edit', ['application' => $payment->application_id, 'step' => 'payment'])
            ->with('success', $status === 'success' ? 'Payment confirmed.' : 'Payment failed.');
    }

    /**
     * cGrate Mobile Money status endpoint (polling).
     */
    public function mobileMoneyStatus(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment->application);

        $force = (bool) $request->boolean('force', false);

        $payment->loadMissing('latestAttempt');
        $attempt = $payment->latestAttempt;

        if ($attempt && $attempt->gateway === 'cgrate' && ! $attempt->status?->isTerminal()) {
            $attempt->forceFill([
                'next_query_at' => now(),
            ])->save();

            if ($force) {
                QueryCGratePaymentAttemptJob::dispatchSync((int) $attempt->id);
            } else {
                QueryCGratePaymentAttemptJob::dispatch((int) $attempt->id);
            }

            $payment->refresh()->loadMissing('latestAttempt');
            $attempt = $payment->latestAttempt;
        }

        return response()->json([
            'payment' => [
                'id' => $payment->id,
                'method' => $payment->method?->value ?? (string) $payment->method,
                'status' => $payment->status?->value ?? (string) $payment->status,
                'provider' => $payment->provider,
                'provider_reference' => $payment->provider_reference,
                'provider_transaction_id' => $payment->provider_transaction_id,
                'mobile_number' => $payment->mobile_number,
                'confirmed_at' => optional($payment->confirmed_at)?->toIso8601String(),
                'failed_at' => optional($payment->failed_at)?->toIso8601String(),
                'rejected_at' => optional($payment->rejected_at)?->toIso8601String(),
                'expires_at' => optional($payment->expires_at)?->toIso8601String(),
            ],
            'attempt' => $attempt ? [
                'id' => $attempt->id,
                'gateway' => $attempt->gateway,
                'status' => $attempt->status?->value ?? (string) $attempt->status,
                'payment_reference' => $attempt->payment_reference,
                'provider_transaction_id' => $attempt->provider_transaction_id,
                'response_code' => $attempt->response_code,
                'response_message' => $attempt->response_message,
                'query_attempts' => $attempt->query_attempts,
                'initiated_at' => optional($attempt->initiated_at)?->toIso8601String(),
                'confirmed_at' => optional($attempt->confirmed_at)?->toIso8601String(),
                'failed_at' => optional($attempt->failed_at)?->toIso8601String(),
                'rejected_at' => optional($attempt->rejected_at)?->toIso8601String(),
                'expired_at' => optional($attempt->expired_at)?->toIso8601String(),
                'last_queried_at' => optional($attempt->last_queried_at)?->toIso8601String(),
                'next_query_at' => optional($attempt->next_query_at)?->toIso8601String(),
            ] : null,
        ]);
    }
}
