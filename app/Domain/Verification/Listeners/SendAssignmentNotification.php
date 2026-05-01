<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Verification\Events\QualificationAssignedToVerifier;
use App\Mail\Verification\QualificationAssignedToVerifierMail;
use App\Models\EmailLog;
use App\Models\SmsLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAssignmentNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(QualificationAssignedToVerifier $event): void
    {
        $assignee = $event->assignedTo;
        $qualification = $event->qualification->loadMissing('application', 'country', 'awardingInstitution');
        $application = $qualification->application;

        $emailLog = EmailLog::create([
            'user_id' => $assignee->id,
            'application_id' => $application->id,
            'email' => $assignee->email,
            'subject' => 'ZAQA: Qualification task assigned for review',
            'template_key' => 'verification_assigned',
            'status' => 'queued',
            'sent_at' => null,
        ]);

        if ($assignee->email) {
            try {
                Mail::to($assignee->email)->queue(new QualificationAssignedToVerifierMail(
                    qualification: $qualification,
                    assignedBy: $event->assignedBy,
                    assignedTo: $assignee,
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
        } else {
            $emailLog->forceFill(['status' => 'skipped'])->save();
        }

        $message = sprintf(
            'ZAQA: Qualification task for application %s has been assigned to you for review.',
            $application->application_number,
        );

        $provider = (string) config('services.sms.provider', 'log');
        $smsLog = SmsLog::create([
            'user_id' => $assignee->id,
            'application_id' => $application->id,
            'phone_number' => $assignee->phone_primary,
            'message_type' => 'verification_assigned',
            'message_body' => $message,
            'provider' => $provider,
            'status' => 'queued',
            'provider_reference' => null,
            'sent_at' => null,
        ]);

        try {
            if ($provider === 'log') {
                Log::info('SMS', ['to' => $assignee->phone_primary, 'message' => $message]);
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

