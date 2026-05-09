<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Verification\Events\QualificationLevel1Completed;
use App\Mail\Verification\QualificationLevel1CompletedMail;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendQualificationLevel1CompletedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(QualificationLevel1Completed $event): void
    {
        $recipient = $event->assignedBy;

        $email = trim((string) ($recipient->email ?? ''));
        if ($email === '') {
            return;
        }

        $emailLog = EmailLog::create([
            'user_id' => $recipient->id,
            'application_id' => $event->qualification->application_id,
            'email' => $email,
            'subject' => 'ZAQA: Level 1 completed qualification task',
            'template_key' => 'verification_qualification_level1_completed',
            'status' => 'queued',
            'sent_at' => null,
        ]);

        try {
            Mail::to($email)->queue(new QualificationLevel1CompletedMail(
                qualification: $event->qualification->fresh(['application']),
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
