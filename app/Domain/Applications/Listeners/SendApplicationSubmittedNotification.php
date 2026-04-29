<?php

namespace App\Domain\Applications\Listeners;

use App\Domain\Applications\Events\ApplicationSubmitted;
use App\Mail\ApplicationSubmittedMail;
use App\Models\EmailLog;
use App\Models\SmsLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendApplicationSubmittedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(ApplicationSubmitted $event): void
    {
        $user = $event->actor;
        $application = $event->application;
        $trackingUrl = route('applicant.applications.track', ['application' => $application->id]);

        $subject = $event->isResubmission
            ? 'ZAQA application resubmitted'
            : 'ZAQA application submitted';

        $emailLog = EmailLog::create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'email' => $user->email,
            'subject' => $subject,
            'template_key' => 'application_submitted',
            'status' => 'queued',
            'sent_at' => null,
        ]);

        try {
            Mail::to($user->email)->send(new ApplicationSubmittedMail(
                recipientName: $user->name,
                applicationNumber: $application->application_number,
                trackingUrl: $trackingUrl,
                isResubmission: $event->isResubmission,
            ));

            $emailLog->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $emailLog->forceFill(['status' => 'failed'])->save();
            throw $e;
        }

        $message = $event->isResubmission
            ? sprintf('ZAQA: Your application %s has been resubmitted successfully.', $application->application_number)
            : sprintf('ZAQA: Your application %s has been submitted successfully.', $application->application_number);

        $provider = (string) config('services.sms.provider', 'log');

        $smsLog = SmsLog::create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'phone_number' => $user->phone_primary,
            'message_type' => $event->isResubmission ? 'application_resubmitted' : 'application_submitted',
            'message_body' => $message,
            'provider' => $provider,
            'status' => 'queued',
            'provider_reference' => null,
            'sent_at' => null,
        ]);

        try {
            if ($provider === 'log') {
                Log::info('SMS', [
                    'to' => $user->phone_primary,
                    'message' => $message,
                ]);
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
