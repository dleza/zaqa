<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Verification\Events\ApplicationLevel1Completed;
use App\Mail\Verification\ApplicationLevel1CompletedMail;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendLevel1CompletedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(ApplicationLevel1Completed $event): void
    {
        $recipient = $event->assignedBy;

        $emailLog = EmailLog::create([
            'user_id' => $recipient->id,
            'application_id' => $event->application->id,
            'email' => $recipient->email,
            'subject' => 'ZAQA: Level 1 review completed',
            'template_key' => 'verification_level1_completed',
            'status' => 'queued',
            'sent_at' => null,
        ]);

        if (! $recipient->email) {
            $emailLog->forceFill(['status' => 'skipped'])->save();

            return;
        }

        try {
            Mail::to($recipient->email)->queue(new ApplicationLevel1CompletedMail(
                application: $event->application,
                level1Actor: $event->level1Actor,
                assignedBy: $recipient,
                findings: $event->findings,
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
}

