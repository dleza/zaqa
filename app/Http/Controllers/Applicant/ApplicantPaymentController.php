<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Documents\ApplicantDocumentService;
use App\Domain\Payments\Exceptions\CyberSourceConfigurationException;
use App\Domain\Payments\Gateways\CyberSource\CyberSourcePaymentGateway;
use App\Domain\Payments\InvoiceService;
use App\Domain\Payments\PaymentService;
use App\Domain\Payments\Presenters\ApplicantPaymentAttemptStatusPresenter;
use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\ConfirmCyberSourceCardPaymentRequest;
use App\Http\Requests\Applicant\CreateCyberSourceCaptureContextRequest;
use App\Http\Requests\Applicant\InitiateMobileMoneyApplicationPaymentRequest;
use App\Http\Requests\Applicant\InitiateMobileMoneyPaymentRequest;
use App\Http\Requests\Applicant\SelectPaymentMethodRequest;
use App\Http\Requests\Applicant\UploadApplicationPaymentProofRequest;
use App\Http\Requests\Applicant\UploadPaymentProofRequest;
use App\Jobs\Payments\QueryCGratePaymentAttemptJob;
use App\Models\Application;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Support\Payments\PaymentQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

    public function createCardCaptureContext(
        CreateCyberSourceCaptureContextRequest $request,
        Application $application,
        PaymentService $payments,
        CyberSourcePaymentGateway $gateway,
    ): JsonResponse {
        $payment = $payments->createDraftPayment($application, PaymentMethod::Card, $request->user());

        $this->ensureCyberSourceProvider($payment);
        $this->ensureCyberSourceProviderReference($payment, $gateway);

        try {
            $captureContext = $gateway->createCaptureContext($payment);
        } catch (CyberSourceConfigurationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        return response()->json([
            'payment_id' => (int) $payment->id,
            'provider_reference' => (string) $payment->provider_reference,
            'capture_context' => (string) ($captureContext['capture_context'] ?? ''),
            'card_networks' => $captureContext['allowed_card_networks'] ?? [],
        ]);
    }

    public function confirmCardPayment(
        ConfirmCyberSourceCardPaymentRequest $request,
        Payment $payment,
        PaymentService $payments,
        CyberSourcePaymentGateway $gateway,
    ): JsonResponse {
        $payment->loadMissing(['application', 'invoice']);
        $this->assertCyberSourceCardPaymentCanBeCharged($payment);
        $this->ensureCyberSourceProviderReference($payment, $gateway);

        try {
            $verified = $gateway->chargeTransientToken(
                $payment,
                (string) $request->validated()['transient_token_jwt'],
            );
        } catch (CyberSourceConfigurationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        $payment = $payments->applyGatewayVerificationResult(
            $payment,
            (string) ($verified['status'] ?? 'failed'),
            $verified,
            'cybersource.card.confirm',
        );

        return response()->json([
            'payment_status' => $payment->status?->value ?? (string) $payment->status,
            'redirect_url' => $this->cardPaymentRedirectUrl($payment),
            'message' => $this->cardPaymentMessage($payment),
        ]);
    }

    public function initiateMobileMoneyForApplication(
        InitiateMobileMoneyApplicationPaymentRequest $request,
        Application $application,
        PaymentService $payments,
        ApplicantPaymentAttemptStatusPresenter $presenter,
    ): RedirectResponse|JsonResponse {
        $this->authorize('view', $application);

        $payment = $payments->createDraftPayment($application, PaymentMethod::MobileMoney, $request->user());

        $payload = [
            'mobile_number' => (string) $request->validated()['mobile_number'],
        ];

        $result = $payments->initiateOnline($payment, $payload, $request->user());

        return $this->mobileMoneyInitiationResponse($request, $result, $presenter, (bool) ($result['already_pending'] ?? false));
    }

    public function initiateMobileMoney(
        InitiateMobileMoneyPaymentRequest $request,
        Payment $payment,
        PaymentService $payments,
        ApplicantPaymentAttemptStatusPresenter $presenter,
    ): RedirectResponse|JsonResponse {
        $this->authorize('view', $payment->application);

        $payload = [
            'mobile_number' => (string) $request->validated()['mobile_number'],
        ];

        $result = $payments->initiateOnline($payment, $payload, $request->user());

        return $this->mobileMoneyInitiationResponse($request, $result, $presenter, (bool) ($result['already_pending'] ?? false));
    }

    /**
     * @param  array{payment: Payment, redirect_url: string|null, attempt_id?: int|null, already_pending?: bool}  $result
     */
    private function mobileMoneyInitiationResponse(
        Request $request,
        array $result,
        ApplicantPaymentAttemptStatusPresenter $presenter,
        bool $alreadyPending,
    ): RedirectResponse|JsonResponse {
        /** @var Payment $payment */
        $payment = $result['payment'];
        $payment->loadMissing('latestAttempt');

        $attempt = $payment->latestAttempt;
        $message = $alreadyPending
            ? 'A payment request is already pending.'
            : 'Payment request sent. Please approve the prompt on your phone.';

        if ($request->expectsJson()) {
            $body = $attempt
                ? $presenter->present($attempt, $payment)
                : [
                    'attempt_id' => null,
                    'status' => ApplicantPaymentAttemptStatusPresenter::STATUS_PENDING,
                    'message' => $message,
                    'paid' => false,
                    'redirect_url' => null,
                    'mobile_number' => $payment->mobile_number,
                    'amount_cents' => (int) $payment->amount_cents,
                    'currency' => (string) ($payment->currency ?? 'ZMW'),
                    'initiated_at' => optional($payment->initiated_at)?->toIso8601String(),
                    'can_retry' => false,
                ];

            $body['already_pending'] = $alreadyPending;
            if ($alreadyPending) {
                $body['message'] = 'A payment request is already pending.';
            }

            return response()->json($body);
        }

        return back()->with('success', $message);
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

        $application = $payment->application()->firstOrFail();
        $payment = $payments->paymentForManualProofUpload($application, $request->user());

        $file = $request->file('file');
        $document = $documents->upload($application, DocumentType::PaymentProof, $file, $request->user());

        $payments->attachProof($payment, $document, $request->user());

        return back()->with('success', 'Proof of payment uploaded. Awaiting finance review.');
    }

    public function returnFromProvider(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize('view', $payment->application);

        $payments->handleGatewayReturn($payment, $request->all());

        return $this->redirectAfterGatewayReturn($payment);
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

        return $this->redirectAfterGatewayReturn($payment);
    }

    private function redirectAfterGatewayReturn(Payment $payment): RedirectResponse
    {
        $payment->refresh()->loadMissing('application');
        $application = $payment->application;

        if ($payment->status === PaymentStatus::Confirmed && $application) {
            return redirect()
                ->route('applicant.applications.feedback.show', $application)
                ->with([
                    'success' => 'Payment confirmed successfully. Your application has been submitted to ZAQA for verification.',
                    'payment_completed' => true,
                ]);
        }

        $isFailure = in_array($payment->status, [
            PaymentStatus::Failed,
            PaymentStatus::Rejected,
            PaymentStatus::Expired,
        ], true);

        $flashKey = $isFailure ? 'error' : 'success';
        $message = $isFailure
            ? 'Payment was not completed. Please try again or choose another payment method.'
            : 'Payment status updated.';

        if ($application && auth()->user()?->can('update', $application)) {
            return redirect()
                ->route('applicant.applications.edit', ['application' => $application->id, 'step' => 'payment'])
                ->with($flashKey, $message);
        }

        return redirect()
            ->route('applicant.applications.show', $application)
            ->with($flashKey, $message);
    }

    private function ensureCyberSourceProvider(Payment $payment): void
    {
        if ($payment->provider !== 'cybersource') {
            $payment->forceFill(['provider' => 'cybersource'])->save();
        }
    }

    private function ensureCyberSourceProviderReference(Payment $payment, CyberSourcePaymentGateway $gateway): void
    {
        if (trim((string) $payment->provider_reference) !== '') {
            return;
        }

        $result = $gateway->initiate($payment, PaymentMethod::Card, []);

        $payment->forceFill([
            'provider' => 'cybersource',
            'provider_reference' => (string) $result['provider_reference'],
            'provider_transaction_id' => $result['provider_transaction_id'] ?: null,
            'raw_payload' => $result['raw_payload'] ?? $payment->raw_payload,
            'last_status_at' => now(),
        ])->save();
    }

    private function assertCyberSourceCardPaymentCanBeCharged(Payment $payment): void
    {
        if ($payment->method !== PaymentMethod::Card || $payment->provider !== 'cybersource') {
            throw ValidationException::withMessages([
                'payment' => 'This payment is not a CyberSource card payment.',
            ]);
        }

        if ($payment->status === PaymentStatus::Confirmed) {
            throw ValidationException::withMessages([
                'payment' => 'Payment is already confirmed.',
            ]);
        }

        if ($payment->status === PaymentStatus::PendingConfirmation) {
            throw ValidationException::withMessages([
                'payment' => 'Payment confirmation is already pending.',
            ]);
        }

        if ($payment->invoice && ($payment->invoice->status === InvoiceStatus::Paid || $payment->invoice->paid_at)) {
            throw ValidationException::withMessages([
                'payment' => 'Payment is already confirmed for this invoice.',
            ]);
        }
    }

    private function cardPaymentRedirectUrl(Payment $payment): ?string
    {
        $payment->loadMissing('application');

        if ($payment->status === PaymentStatus::Confirmed && $payment->application) {
            return route('applicant.applications.feedback.show', $payment->application);
        }

        if (in_array($payment->status, [PaymentStatus::Failed, PaymentStatus::Rejected, PaymentStatus::Expired], true) && $payment->application) {
            return route('applicant.applications.edit', [
                'application' => $payment->application_id,
                'step' => 'payment',
            ]);
        }

        return null;
    }

    private function cardPaymentMessage(Payment $payment): string
    {
        return match ($payment->status) {
            PaymentStatus::Confirmed => 'Payment confirmed successfully. Your application has been submitted to ZAQA for verification.',
            PaymentStatus::PendingConfirmation => 'Payment is pending confirmation.',
            PaymentStatus::Rejected => 'Payment was rejected. Please try again or choose another payment method.',
            PaymentStatus::Expired => 'Payment expired. Please try again or choose another payment method.',
            PaymentStatus::Failed => 'Payment failed. Please try again or choose another payment method.',
            default => 'Payment status updated.',
        };
    }

    /**
     * Applicant-safe payment attempt status (polling).
     */
    public function attemptStatus(
        Request $request,
        PaymentAttempt $attempt,
        ApplicantPaymentAttemptStatusPresenter $presenter,
    ): JsonResponse {
        $attempt->loadMissing('payment.application');
        $payment = $attempt->payment;

        if (! $payment) {
            abort(404);
        }

        $this->authorize('view', $payment->application);

        if ($attempt->gateway === 'cgrate' && ! $attempt->status?->isTerminal() && $payment->status !== PaymentStatus::Confirmed) {
            $attempt->forceFill(['next_query_at' => now()])->save();

            if ((string) config('queue.default') !== 'sync') {
                QueryCGratePaymentAttemptJob::dispatch((int) $attempt->id)
                    ->onQueue(PaymentQueue::polling());
            }
        }

        $attempt->refresh();
        $payment->refresh();

        return response()->json($presenter->present($attempt, $payment));
    }

    /**
     * @deprecated Use attemptStatus for applicant polling. Kept for backward compatibility.
     */
    public function mobileMoneyStatus(
        Request $request,
        Payment $payment,
        ApplicantPaymentAttemptStatusPresenter $presenter,
    ): JsonResponse {
        $this->authorize('view', $payment->application);

        $payment->loadMissing('latestAttempt');
        $attempt = $payment->latestAttempt;

        if (! $attempt) {
            return response()->json([
                'status' => ApplicantPaymentAttemptStatusPresenter::STATUS_PENDING,
                'message' => 'Waiting for payment approval.',
                'paid' => false,
                'redirect_url' => null,
            ]);
        }

        return $this->attemptStatus($request, $attempt, $presenter);
    }
}
