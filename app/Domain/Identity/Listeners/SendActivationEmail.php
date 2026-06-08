<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Events\ActivationEmailTokenIssued;
use App\Domain\Notifications\OutboundMailService;
use App\Mail\ActivationEmailMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendActivationEmail implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(ActivationEmailTokenIssued $event): void
    {
        $mail = app(OutboundMailService::class);
        $user = $event->user;
        $email = trim((string) ($user->email ?? ''));
        if ($email === '') {
            return;
        }

        $mail->queue(
            mailable: new ActivationEmailMail(
                recipientName: $user->name,
                activationUrl: route('activation.email.verify', ['token' => $event->token]),
                expiresAt: $event->expiresAt,
            ),
            to: $email,
            logContext: [
                'user_id' => $user->id,
                'application_id' => null,
                'email' => $email,
                'subject' => 'Activate your ZAQA account',
                'template_key' => 'activation_email',
            ],
        );
    }
}
