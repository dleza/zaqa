<?php

namespace Tests\Unit;

use App\Domain\Payments\Gateways\CGrate\CGratePaymentResponse;
use PHPUnit\Framework\TestCase;

class CGratePaymentResponseTest extends TestCase
{
    public function test_status_helpers_map_expected_codes(): void
    {
        $pending = new CGratePaymentResponse(206, 'PENDING_APPROVAL', null);
        $this->assertTrue($pending->isPending());
        $this->assertFalse($pending->isTerminal());

        $approved = new CGratePaymentResponse(226, 'PAYMENT_PROCESSED', 'PID');
        $this->assertTrue($approved->isApproved());
        $this->assertTrue($approved->isTerminal());

        $rejected = new CGratePaymentResponse(208, 'REJECTED', null);
        $this->assertTrue($rejected->isRejected());
        $this->assertTrue($rejected->isTerminal());

        $failed = new CGratePaymentResponse(7, 'INVALID_MSISDN', null);
        $this->assertTrue($failed->isFailed());
        $this->assertTrue($failed->isTerminal());

        $unknown = new CGratePaymentResponse(12, 'UNKNOWN_TRANSACTION', null);
        $this->assertTrue($unknown->isUnknown());
        $this->assertFalse($unknown->isTerminal());
    }
}

