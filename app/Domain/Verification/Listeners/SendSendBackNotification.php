<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Verification\Events\ApplicationSentBackToApplicant;
use App\Mail\Verification\ApplicationSentBackToApplicantMail;
use App\Models\EmailLog;
use App\Models\SmsLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSendBackNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(ApplicationSentBackToApplicant $event): void
    {
        $application = $event->application;
        $user = $application->applicant()->first();
        if (! $user) {
            return;
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email !== '') {
            $emailLog = EmailLog::create([
                'user_id' => $user->id,
                'application_id' => $application->id,
                'email' => $email,
                'subject' => 'ZAQA: Application sent back for amendments',
                'template_key' => 'verification_sent_back',
                'status' => 'queued',
                'sent_at' => null,
            ]);

            try {
                Mail::to($email)->queue(new ApplicationSentBackToApplicantMail(
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
        }

        $message = sprintf('ZAQA: Application %s was sent back for amendments. Please login to view the comment and resubmit.', $application->application_number);
        $provider = (string) config('services.sms.provider', 'log');

        $phone = trim((string) ($user->phone_primary ?? ''));
        if ($phone !== '') {
            $smsLog = SmsLog::create([
                'user_id' => $user->id,
                'application_id' => $application->id,
                'phone_number' => $phone,
                'message_type' => 'verification_sent_back',
                'message_body' => $message,
                'provider' => $provider,
                'status' => 'queued',
                'provider_reference' => null,
                'sent_at' => null,
            ]);

            try {
                if ($provider === 'log') {
                    Log::info('SMS', ['to' => $phone, 'message' => $message]);
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
}
