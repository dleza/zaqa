<?php

namespace App\Domain\Notifications;

use App\Models\EmailLog;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OutboundMailService
{
    /**
     * Queue a mailable for background delivery. Failures are logged and never thrown.
     *
     * @param  string|array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array{
     *     user_id?: int|null,
     *     application_id?: int|null,
     *     email: string,
     *     subject: string,
     *     template_key: string
     * }  $logContext
     */
    public function queue(Mailable $mailable, string|array $to, array $logContext, array $cc = []): bool
    {
        $toList = is_array($to) ? array_values(array_filter($to)) : [trim($to)];
        $primaryEmail = (string) ($logContext['email'] ?? $toList[0] ?? '');

        if ($primaryEmail === '' && $toList === []) {
            return false;
        }

        $log = EmailLog::create([
            'user_id' => $logContext['user_id'] ?? null,
            'application_id' => $logContext['application_id'] ?? null,
            'email' => $primaryEmail !== '' ? $primaryEmail : (string) ($toList[0] ?? ''),
            'subject' => (string) ($logContext['subject'] ?? ''),
            'template_key' => (string) ($logContext['template_key'] ?? 'generic'),
            'status' => 'queued',
            'sent_at' => null,
        ]);

        try {
            $mailable->onQueue(NotificationQueue::mail());

            $mailer = Mail::to($toList);
            if ($cc !== []) {
                $mailer->cc($cc);
            }

            $mailer->queue($mailable);

            $log->forceFill([
                'status' => 'queued',
            ])->save();

            return true;
        } catch (\Throwable $e) {
            $log->forceFill(['status' => 'failed'])->save();

            Log::warning('Outbound email queue failed.', [
                'template_key' => $logContext['template_key'] ?? null,
                'email' => $primaryEmail,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
