<?php

namespace Tests\Unit;

use App\Domain\Payments\Gateways\CyberSource\CyberSourceStatusMapper;
use App\Enums\PaymentStatus;
use CyberSource\Model\PtsV2PaymentsPost201Response;
use CyberSource\Model\PtsV2PaymentsPost201ResponseErrorInformation;
use Tests\TestCase;

class CyberSourceStatusMapperTest extends TestCase
{
    public function test_maps_authorized_response_to_confirmed(): void
    {
        $mapper = new CyberSourceStatusMapper();

        $this->assertSame(PaymentStatus::Confirmed->value, $mapper->toNormalizedStatus('AUTHORIZED'));
    }

    public function test_maps_pending_review_response_to_pending_confirmation(): void
    {
        $mapper = new CyberSourceStatusMapper();

        $this->assertSame(PaymentStatus::PendingConfirmation->value, $mapper->toNormalizedStatus('PENDING_REVIEW'));
        $this->assertSame('pending', $mapper->toGatewayVerificationStatus('PENDING_REVIEW'));
    }

    public function test_maps_decline_reason_to_rejected(): void
    {
        $mapper = new CyberSourceStatusMapper();
        $response = new PtsV2PaymentsPost201Response([
            'status' => 'DECLINED',
            'errorInformation' => new PtsV2PaymentsPost201ResponseErrorInformation([
                'reason' => 'PROCESSOR_DECLINED',
            ]),
        ]);

        $this->assertSame(PaymentStatus::Rejected->value, $mapper->toNormalizedStatus($response));
    }

    public function test_maps_expired_response_to_expired(): void
    {
        $mapper = new CyberSourceStatusMapper();

        $this->assertSame(PaymentStatus::Expired->value, $mapper->toNormalizedStatus('TOKEN_EXPIRED'));
    }

    public function test_unknown_response_never_defaults_to_confirmed(): void
    {
        $mapper = new CyberSourceStatusMapper();

        $this->assertSame(PaymentStatus::Failed->value, $mapper->toNormalizedStatus('SOMETHING_NEW'));
    }
}
