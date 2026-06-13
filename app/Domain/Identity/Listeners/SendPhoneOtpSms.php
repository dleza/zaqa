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

        $sms->queueTemplate(
            templateKey: 'activation_otp',
            placeholders: [
                'code' => $event->code,
                'expires_at' => $event->expiresAt
                    ->timezone(config('app.timezone'))
                    ->format('d M Y H:i'),
            ],
            phone: $phone,
            userId: $user->id,
        );
    }
}
