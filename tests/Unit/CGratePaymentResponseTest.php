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

        $invalidReference = new CGratePaymentResponse(106, 'Invalid transaction reference', 'FSG3UKOI1');
        $this->assertTrue($invalidReference->isPending());
        $this->assertFalse($invalidReference->isFailed());
        $this->assertFalse($invalidReference->isTerminal());

        $unknown = new CGratePaymentResponse(12, 'UNKNOWN_TRANSACTION', null);
        $this->assertTrue($unknown->isUnknown());
        $this->assertFalse($unknown->isTerminal());
    }

    public function test_query_response_code_zero_with_payment_id_is_approved(): void
    {
        $resp = new CGratePaymentResponse(
            responseCode: 0,
            responseMessage: 'Successful',
            paymentId: 'MP260623.1058.Z36675',
            raw: ['operation' => 'queryCustomerPayment'],
        );

        $this->assertTrue($resp->isApproved());
        $this->assertTrue($resp->isTerminal());
        $this->assertFalse($resp->isPending());
    }

    public function test_query_response_code_zero_without_payment_id_is_not_approved(): void
    {
        $resp = new CGratePaymentResponse(
            responseCode: 0,
            responseMessage: 'Successful',
            paymentId: null,
            raw: ['operation' => 'queryCustomerPayment'],
        );

        $this->assertFalse($resp->isApproved());
        $this->assertFalse($resp->isTerminal());
    }

    public function test_initiation_response_code_zero_with_payment_id_is_not_approved(): void
    {
        $resp = new CGratePaymentResponse(
            responseCode: 0,
            responseMessage: 'Successful',
            paymentId: 'FSG3P54U4',
            raw: ['operation' => 'processCustomerPayment'],
        );

        $this->assertTrue($resp->isSuccessfulRequest());
        $this->assertFalse($resp->isApproved());
        $this->assertFalse($resp->isTerminal());
    }
}

