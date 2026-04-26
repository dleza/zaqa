<?php

namespace App\Domain\Finance;

use App\Domain\Finance\Events\PaymentProofApproved;
use App\Domain\Finance\Events\PaymentProofRejected;
use App\Domain\Payments\PaymentService;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PaymentProofReviewService
{
    public function __construct(private readonly PaymentService $payments) {}

    public function approve(Payment $payment, User $actor, ?string $comment = null): Payment
    {
        $payment->loadMissing('invoice');

        if (! in_array($payment->method, [PaymentMethod::BankDeposit, PaymentMethod::BankTransfer], true)) {
            throw ValidationException::withMessages(['method' => 'Only bank deposit/transfer payments can be reviewed here.']);
        }

        // Idempotent approval: if already confirmed/paid, do nothing.
        if ($payment->status === PaymentStatus::Confirmed || $payment->invoice?->status === InvoiceStatus::Paid) {
            return $payment;
        }

        if ($payment->status !== PaymentStatus::AwaitingFinanceReview) {
            throw ValidationException::withMessages(['status' => 'Only payments awaiting finance review can be approved.']);
        }

        $updated = $this->payments->financeApprove($payment, $actor, $comment);

        event(new PaymentProofApproved(payment: $updated, actor: $actor, comment: $comment));

        return $updated;
    }

    public function reject(Payment $payment, User $actor, string $reason): Payment
    {
        $payment->loadMissing('invoice');

        if (! in_array($payment->method, [PaymentMethod::BankDeposit, PaymentMethod::BankTransfer], true)) {
            throw ValidationException::withMessages(['method' => 'Only bank deposit/transfer payments can be reviewed here.']);
        }

        if ($payment->status === PaymentStatus::Confirmed || $payment->invoice?->status === InvoiceStatus::Paid) {
            throw ValidationException::withMessages(['status' => 'Cannot reject a payment that is already confirmed.']);
        }

        if ($payment->status !== PaymentStatus::AwaitingFinanceReview) {
            throw ValidationException::withMessages(['status' => 'Only payments awaiting finance review can be rejected.']);
        }

        $updated = $this->payments->financeReject($payment, $actor, $reason);

        event(new PaymentProofRejected(payment: $updated, actor: $actor, reason: $reason));

        return $updated;
    }
}
