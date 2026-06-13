<?php

namespace Tests\Unit;

use App\Domain\Payments\Presenters\ApplicantPaymentAttemptStatusPresenter;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Tests\TestCase;

class ApplicantPaymentAttemptStatusPresenterTest extends TestCase
{
    public function test_maps_unknown_attempt_to_pending_for_applicant(): void
    {
        $presenter = new ApplicantPaymentAttemptStatusPresenter;

        $payment = new Payment([
            'status' => PaymentStatus::PendingConfirmation,
            'application_id' => 1,
            'amount_cents' => 10000,
            'currency' => 'ZMW',
        ]);
        $payment->id = 10;

        $attempt = new PaymentAttempt([
            'status' => PaymentAttemptStatus::Unknown,
            'mobile_number' => '0971000000',
            'amount_cents' => 10000,
            'currency' => 'ZMW',
        ]);
        $attempt->id = 5;

        $presented = $presenter->present($attempt, $payment);

        $this->assertSame('pending', $presented['status']);
        $this->assertFalse($presented['paid']);
        $this->assertSame('Waiting for payment approval.', $presented['message']);
    }

    public function test_maps_confirmed_to_successful(): void
    {
        $presenter = new ApplicantPaymentAttemptStatusPresenter;

        $payment = new Payment([
            'status' => PaymentStatus::Confirmed,
            'application_id' => 1,
            'amount_cents' => 10000,
            'currency' => 'ZMW',
        ]);
        $payment->id = 10;

        $attempt = new PaymentAttempt([
            'status' => PaymentAttemptStatus::Confirmed,
            'mobile_number' => '0971000000',
            'amount_cents' => 10000,
            'currency' => 'ZMW',
        ]);
        $attempt->id = 5;

        $presented = $presenter->present($attempt, $payment);

        $this->assertSame('successful', $presented['status']);
        $this->assertTrue($presented['paid']);
        $this->assertStringContainsString('submitted for verification', $presented['message']);
    }

    public function test_maps_failed_attempt_to_failed_with_retry(): void
    {
        $presenter = new ApplicantPaymentAttemptStatusPresenter;

        $payment = new Payment([
            'status' => PaymentStatus::Failed,
            'application_id' => 1,
            'amount_cents' => 10000,
            'currency' => 'ZMW',
        ]);
        $payment->id = 10;

        $attempt = new PaymentAttempt([
            'status' => PaymentAttemptStatus::Failed,
            'mobile_number' => '0971000000',
            'amount_cents' => 10000,
            'currency' => 'ZMW',
        ]);
        $attempt->id = 5;

        $presented = $presenter->present($attempt, $payment);

        $this->assertSame('failed', $presented['status']);
        $this->assertTrue($presented['can_retry']);
        $this->assertStringContainsString('try again', $presented['message']);
    }
}
