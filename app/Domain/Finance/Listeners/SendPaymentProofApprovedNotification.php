<?php

namespace App\Domain\Finance\Listeners;

use App\Domain\Finance\Events\PaymentProofApproved;
use App\Mail\Finance\PaymentProofApprovedMail;
use App\Models\EmailLog;
use App\Models\SmsLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPaymentProofApprovedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PaymentProofApproved $event): void
    {
        $payment = $event->payment->loadMissing('application.applicant');
        $application = $payment->application;
        $user = $application?->applicant;
        if (! $application || ! $user) {
            return;
        }

        $emailLog = EmailLog::create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'email' => $user->email,
            'subject' => 'ZAQA: Payment confirmed',
            'template_key' => 'finance_payment_approved',
            'status' => 'queued',
            'sent_at' => null,
        ]);

        if ($user->email) {
            try {
                Mail::to($user->email)->queue(new PaymentProofApprovedMail(
                    payment: $payment,
                    application: $application,
                    applicant: $user,
                    actor: $event->actor,
                    comment: $event->comment,
                ));

                $emailLog->forceFill([
                    'status' => 'sent',
                    'sent_at' => now(),
                ])->save();
            } catch (\Throwable $e) {
                $emailLog->forceFill(['status' => 'failed'])->save();
                throw $e;
            }
        } else {
            $emailLog->forceFill(['status' => 'skipped'])->save();
        }

        $message = sprintf(
            'ZAQA: Payment confirmed for application %s. You may continue with your application in the portal.',
            $application->application_number
        );
        $provider = (string) config('services.sms.provider', 'log');

        $smsLog = SmsLog::create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'phone_number' => $user->phone_primary,
            'message_type' => 'finance_payment_approved',
            'message_body' => $message,
            'provider' => $provider,
            'status' => 'queued',
            'provider_reference' => null,
            'sent_at' => null,
        ]);

        try {
            if ($provider === 'log') {
                Log::info('SMS', ['to' => $user->phone_primary, 'message' => $message]);
            }

            $smsLog->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $smsLog->forceFill(['status' => 'failed'])->save();
            throw $e;
        }
    }
}
