<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Events\PhoneOtpIssued;
use App\Domain\Notifications\OutboundSmsService;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendPhoneOtpSms implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(PhoneOtpIssued $event): void
    {
        $sms = app(OutboundSmsService::class);
        $user = $event->user;
        $phone = trim((string) ($user->phone_primary ?? ''));
        if ($phone === '') {
            return;
        }

        $message = sprintf(
            'Your ZAQA OTP code is %s. It expires at %s.',
            $event->code,
            $event->expiresAt->toDayDateTimeString()
        );

        $sms->send(
            phone: $phone,
            message: $message,
            messageType: 'activation_otp',
            userId: $user->id,
        );
    }
}
