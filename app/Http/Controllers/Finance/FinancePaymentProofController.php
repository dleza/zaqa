<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Payments\PaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ReviewPaymentProofRequest;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancePaymentProofController extends Controller
{
    public function index(Request $request): Response
    {
        $payments = Payment::query()
            ->with(['application', 'proofDocument'])
            ->where('status', \App\Enums\PaymentStatus::AwaitingFinanceReview->value)
            ->latest('id')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'application_id' => $p->application_id,
                'method' => $p->method?->value ?? (string) $p->method,
                'status' => $p->status?->value ?? (string) $p->status,
                'created_at' => optional($p->created_at)?->toIso8601String(),
                'application_number' => $p->application?->application_number,
                'proof_document_id' => $p->proof_document_id,
            ]);

        return Inertia::render('Finance/PaymentProofQueue', [
            'payments' => $payments,
        ]);
    }

    public function approve(ReviewPaymentProofRequest $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $payments->financeApprove($payment, $request->user(), $request->validated()['comment'] ?? null);

        return back()->with('success', 'Payment approved.');
    }

    public function reject(ReviewPaymentProofRequest $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $reason = (string) ($request->validated()['reason'] ?? '');
        $payments->financeReject($payment, $request->user(), $reason);

        return back()->with('success', 'Payment rejected.');
    }
}

