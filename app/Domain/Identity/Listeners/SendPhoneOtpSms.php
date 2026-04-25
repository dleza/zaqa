<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Events\PhoneOtpIssued;
use App\Models\SmsLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendPhoneOtpSms implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PhoneOtpIssued $event): void
    {
        $user = $event->user;

        $message = sprintf(
            'Your ZAQA OTP code is %s. It expires at %s.',
            $event->code,
            $event->expiresAt->toDayDateTimeString()
        );

        $provider = (string) config('services.sms.provider', 'log');

        $log = SmsLog::create([
            'user_id' => $user->id,
            'application_id' => null,
            'phone_number' => $user->phone_primary,
            'message_type' => 'activation_otp',
            'message_body' => $message,
            'provider' => $provider,
            'status' => 'queued',
            'provider_reference' => null,
            'sent_at' => null,
        ]);

        try {
            if ($provider === 'log') {
                Log::info('SMS OTP', [
                    'to' => $user->phone_primary,
                    'message' => $message,
                ]);
            }

            $log->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $log->forceFill([
                'status' => 'failed',
            ])->save();

            throw $e;
        }
    }
}

