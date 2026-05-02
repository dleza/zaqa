<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Verification\Events\QualificationSentBackToApplicant;
use App\Mail\Verification\QualificationSentBackToApplicantMail;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendQualificationSendBackNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(QualificationSentBackToApplicant $event): void
    {
        $application = $event->application;
        $user = $application->applicant()->first();
        if (! $user) {
            return;
        }

        $emailLog = EmailLog::create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'email' => $user->email,
            'subject' => 'ZAQA: Qualification amendment required',
            'template_key' => 'verification_qualification_sent_back',
            'status' => 'queued',
            'sent_at' => null,
        ]);

        if (! $user->email) {
            $emailLog->forceFill(['status' => 'skipped'])->save();

            return;
        }

        try {
            Mail::to($user->email)->queue(new QualificationSentBackToApplicantMail(
                qualification: $event->qualification,
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
}
