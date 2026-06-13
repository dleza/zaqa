<?php

namespace App\Domain\Finance\Listeners;

use App\Domain\Finance\Events\PaymentProofApproved;
use App\Domain\Notifications\OutboundMailService;
use App\Domain\Notifications\OutboundSmsService;
use App\Mail\Finance\PaymentProofApprovedMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendPaymentProofApprovedNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(PaymentProofApproved $event): void
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
                mailable: new PaymentProofApprovedMail(
                    payment: $payment,
                    application: $application,
                    applicant: $user,
                    actor: $event->actor,
                    comment: $event->comment,
                ),
                to: $email,
                logContext: [
                    'user_id' => $user->id,
                    'application_id' => $application->id,
                    'email' => $email,
                    'subject' => 'ZAQA: Payment confirmed',
                    'template_key' => 'finance_payment_approved',
                ],
            );
        }

        $phone = trim((string) ($user->phone_primary ?? ''));
        if ($phone !== '') {
            $sms->queueTemplate(
                templateKey: 'payment_approved',
                placeholders: [
                    'application_number' => (string) $application->application_number,
                ],
                phone: $phone,
                userId: $user->id,
                applicationId: $application->id,
            );
        }
    }
}
