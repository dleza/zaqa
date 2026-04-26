<?php

namespace App\Domain\Finance\Listeners;

use App\Domain\Finance\Events\PaymentProofRejected;
use App\Mail\Finance\PaymentProofRejectedMail;
use App\Models\EmailLog;
use App\Models\SmsLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPaymentProofRejectedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PaymentProofRejected $event): void
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
            'subject' => 'ZAQA: Payment proof rejected',
            'template_key' => 'finance_payment_rejected',
            'status' => 'queued',
            'sent_at' => null,
        ]);

        if ($user->email) {
            try {
                Mail::to($user->email)->queue(new PaymentProofRejectedMail(
                    payment: $payment,
                    application: $application,
                    applicant: $user,
                    actor: $event->actor,
                    reason: $event->reason,
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
            'ZAQA: Payment proof rejected for application %s. Reason: %s. Please login and upload a corrected proof.',
            $application->application_number,
            $event->reason
        );
        $provider = (string) config('services.sms.provider', 'log');

        $smsLog = SmsLog::create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'phone_number' => $user->phone_primary,
            'message_type' => 'finance_payment_rejected',
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
