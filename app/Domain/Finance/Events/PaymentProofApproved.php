<?php

namespace App\Domain\Finance\Events;

use App\Models\Payment;
use App\Models\User;

class PaymentProofApproved
{
    public function __construct(
        public readonly Payment $payment,
        public readonly User $actor,
        public readonly ?string $comment = null,
    ) {}
}
