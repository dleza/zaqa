<?php

namespace App\Domain\Payments\Presenters;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;

class ApplicantPaymentAttemptStatusPresenter
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array{
     *     attempt_id: int,
     *     status: string,
     *     message: string,
     *     paid: bool,
     *     redirect_url: string|null,
     *     mobile_number: string|null,
     *     amount_cents: int,
     *     currency: string,
     *     initiated_at: string|null,
     *     can_retry: bool
     * }
     */
    public function present(PaymentAttempt $attempt, Payment $payment): array
    {
        $status = $this->mapStatus($attempt, $payment);

        return [
            'attempt_id' => (int) $attempt->id,
            'status' => $status,
            'message' => $this->message($status),
            'paid' => $status === self::STATUS_SUCCESSFUL,
            'redirect_url' => $status === self::STATUS_SUCCESSFUL
                ? $this->successRedirectUrl($payment)
                : null,
            'mobile_number' => $attempt->mobile_number,
            'amount_cents' => (int) $attempt->amount_cents,
            'currency' => (string) ($attempt->currency ?? 'ZMW'),
            'initiated_at' => optional($attempt->initiated_at)?->toIso8601String(),
            'can_retry' => $status === self::STATUS_FAILED,
        ];
    }

    /**
     * Applicant-safe summary for initial page load (no redirect_url).
     *
     * @return array{
     *     id: int,
     *     status: string,
     *     message: string,
     *     mobile_number: string|null,
     *     amount_cents: int,
     *     currency: string,
     *     initiated_at: string|null,
     *     can_retry: bool
     * }
     */
    public function presentSummary(PaymentAttempt $attempt, Payment $payment): array
    {
        $presented = $this->present($attempt, $payment);

        return [
            'id' => $presented['attempt_id'],
            'status' => $presented['status'],
            'message' => $presented['message'],
            'mobile_number' => $presented['mobile_number'],
            'amount_cents' => $presented['amount_cents'],
            'currency' => $presented['currency'],
            'initiated_at' => $presented['initiated_at'],
            'can_retry' => $presented['can_retry'],
        ];
    }

    public function mapStatus(PaymentAttempt $attempt, Payment $payment): string
    {
        if ($payment->status === PaymentStatus::Confirmed || $attempt->status === PaymentAttemptStatus::Confirmed) {
            return self::STATUS_SUCCESSFUL;
        }

        if ($this->isFailedAttempt($attempt, $payment)) {
            return self::STATUS_FAILED;
        }

        return self::STATUS_PENDING;
    }

    public function message(string $status): string
    {
        return match ($status) {
            self::STATUS_SUCCESSFUL => 'Payment confirmed. Your application has been submitted for verification.',
            self::STATUS_FAILED => 'Payment was not completed. You can try again or choose another payment method.',
            default => 'Waiting for payment approval.',
        };
    }

    private function isFailedAttempt(PaymentAttempt $attempt, Payment $payment): bool
    {
        if (in_array($payment->status, [PaymentStatus::Failed, PaymentStatus::Rejected, PaymentStatus::Expired], true)) {
            return true;
        }

        return in_array($attempt->status, [
            PaymentAttemptStatus::Failed,
            PaymentAttemptStatus::Rejected,
            PaymentAttemptStatus::Expired,
        ], true);
    }

    private function successRedirectUrl(Payment $payment): string
    {
        $payment->loadMissing('application');
        $application = $payment->application;

        if ($application?->canReceiveApplicantServiceFeedback()) {
            return route('applicant.applications.feedback.show', $application);
        }

        return route('applicant.applications.show', $application ?? $payment->application_id);
    }
}
