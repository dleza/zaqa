<?php

namespace App\Domain\Finance\Listeners;

use App\Domain\Finance\Events\PaymentProofRejected;
use App\Domain\Notifications\OutboundMailService;
use App\Domain\Notifications\OutboundSmsService;
use App\Mail\Finance\PaymentProofRejectedMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendPaymentProofRejectedNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(PaymentProofRejected $event): void
    {
        $mail = app(OutboundMailService::class);
        $sms = app(OutboundSmsService::class);
        $payment = $event->payment->loadMissing('application.applicant');
        $application = $payment->application;
        $user = $application?->applicant;
        if (! $application || ! $user) {
            return;
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email !== '') {
            $mail->queue(
                mailable: new PaymentProofRejectedMail(
                    payment: $payment,
                    application: $application,
                    applicant: $user,
                    actor: $event->actor,
                    reason: $event->reason,
                ),
                to: $email,
                logContext: [
                    'user_id' => $user->id,
                    'application_id' => $application->id,
                    'email' => $email,
                    'subject' => 'ZAQA: Payment proof rejected',
                    'template_key' => 'finance_payment_rejected',
                ],
            );
        }

        $phone = trim((string) ($user->phone_primary ?? ''));
        if ($phone !== '') {
            $sms->send(
                phone: $phone,
                message: sprintf(
                    'ZAQA: Payment proof rejected for application %s. Reason: %s. Please login and upload a corrected proof.',
                    $application->application_number,
                    $event->reason
                ),
                messageType: 'finance_payment_rejected',
                userId: $user->id,
                applicationId: $application->id,
            );
        }
    }
}
