<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Events\ActivationEmailTokenIssued;
use App\Mail\ActivationEmailMail;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendActivationEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(ActivationEmailTokenIssued $event): void
    {
        $user = $event->user;

        $email = trim((string) ($user->email ?? ''));
        if ($email === '') {
            return;
        }

        $activationUrl = route('activation.email.verify', [
            'token' => $event->token,
        ]);

        $subject = 'Activate your ZAQA account';

        $log = EmailLog::create([
            'user_id' => $user->id,
            'application_id' => null,
            'email' => $email,
            'subject' => $subject,
            'template_key' => 'activation_email',
            'status' => 'queued',
            'sent_at' => null,
        ]);

        try {
            Mail::to($email)->send(new ActivationEmailMail(
                recipientName: $user->name,
                activationUrl: $activationUrl,
                expiresAt: $event->expiresAt,
            ));

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
