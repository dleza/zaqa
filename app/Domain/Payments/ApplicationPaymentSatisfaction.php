<?php

namespace App\Domain\Payments;

use App\Domain\Fees\QualificationFeeResolver;
use App\Enums\PaymentStatus;
use App\Models\Application;

/**
 * Whether cumulative confirmed payments cover the current fee total for all qualifications.
 */
final class ApplicationPaymentSatisfaction
{
    public function __construct(
        private readonly QualificationFeeResolver $fees,
    ) {}

    public function confirmedPaymentsTotalCents(Application $application): int
    {
        $application->loadMissing('payments');

        return (int) $application->payments
            ->filter(fn ($p) => $p->status === PaymentStatus::Confirmed)
            ->sum('amount_cents');
    }

    public function outstandingCents(Application $application): int
    {
        $required = $this->fees->totalVerificationFeesCents($application);
        $paid = $this->confirmedPaymentsTotalCents($application);

        return max(0, $required - $paid);
    }

    public function isSatisfied(Application $application): bool
    {
        $application->loadMissing('qualifications');
        if ($application->qualifications->isEmpty()) {
            return false;
        }

        return $this->outstandingCents($application) === 0;
    }
}
