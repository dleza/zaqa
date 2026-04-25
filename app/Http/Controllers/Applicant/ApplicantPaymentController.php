<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Documents\ApplicantDocumentService;
use App\Domain\Payments\InvoiceService;
use App\Domain\Payments\PaymentService;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\InitiateMobileMoneyPaymentRequest;
use App\Http\Requests\Applicant\SelectPaymentMethodRequest;
use App\Http\Requests\Applicant\UploadPaymentProofRequest;
use App\Models\Application;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $payments->selectMethod($application, $method, $request->user());

        return back()->with('success', 'Payment method selected.');
    }

    public function initiateCard(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize('update', $payment->application);

        $result = $payments->initiateOnline($payment, [], $request->user());
        $redirectUrl = $result['redirect_url'] ?? null;

        return $redirectUrl
            ? redirect()->away($redirectUrl)
            : back()->with('error', 'Could not initiate card payment.');
    }

    public function initiateMobileMoney(InitiateMobileMoneyPaymentRequest $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize('update', $payment->application);

        $payload = [
            'mobile_number' => (string) $request->validated()['mobile_number'],
        ];

        $payments->initiateOnline($payment, $payload, $request->user());

        return back()->with('success', 'Mobile Money payment initiated. Please approve the prompt on your phone.');
    }

    public function uploadProof(UploadPaymentProofRequest $request, Payment $payment, ApplicantDocumentService $documents, PaymentService $payments): RedirectResponse
    {
        $this->authorize('update', $payment->application);

        $file = $request->file('file');

        $application = $payment->application()->firstOrFail();

        $document = $documents->upload($application, DocumentType::PaymentProof, $file, $request->user());

        $payments->attachProof($payment, $document, $request->user());

        return back()->with('success', 'Proof of payment uploaded. Awaiting finance review.');
    }

    public function returnFromProvider(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize('update', $payment->application);

        $payments->handleGatewayReturn($payment, $request->all());

        return redirect()->route('applicant.applications.edit', ['application' => $payment->application_id, 'step' => 'payment'])
            ->with('success', 'Payment status updated.');
    }

    /**
     * Test provider redirect simulation (dev/test driver).
     */
    public function testRedirect(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize('update', $payment->application);

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
}

