<?php

namespace App\Domain\Finance\Listeners;

use App\Domain\Finance\Events\PaymentProofSubmitted;
use App\Mail\Finance\BankTransferProofSubmittedMail;
use App\Models\EmailLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBankTransferProofSubmittedNotification
{
    public function handle(PaymentProofSubmitted $event): void
    {
        $payment = $event->payment->loadMissing(['application.applicant', 'invoice', 'proofDocument']);
        $application = $payment->application;
        $applicant = $application?->applicant;

        if (! $application || ! $applicant) {
            return;
        }

        $recipients = array_values(array_filter(
            (array) config('payments.bank_transfer.pop_notification_emails', []),
            static fn ($email) => is_string($email) && trim($email) !== ''
        ));

        if ($recipients === []) {
            return;
        }

        $to = array_shift($recipients);
        $cc = $recipients;

        $proof = $payment->proofDocument;
        $attachMaxBytes = max(0, (int) config('payments.bank_transfer.mail_attachment_max_bytes', 5 * 1024 * 1024));
        $attachProof = $proof !== null && (int) ($proof->size_bytes ?? 0) > 0 && (int) $proof->size_bytes <= $attachMaxBytes;

        $mailable = new BankTransferProofSubmittedMail(
            payment: $payment,
            application: $application,
            applicant: $applicant,
            proofDocument: $proof,
            financeReviewUrl: route('admin.finance.payment_proofs.show', ['payment' => $payment->id]),
            attachProof: $attachProof,
        );

        $log = EmailLog::create([
            'user_id' => null,
            'application_id' => $application->id,
            'email' => $to,
            'subject' => 'ZAQA: Bank transfer proof submitted',
            'template_key' => 'finance_payment_proof_submitted',
            'status' => 'queued',
            'sent_at' => null,
        ]);

        try {
            $mailer = Mail::to($to);
            if ($cc !== []) {
                $mailer->cc($cc);
            }
            $mailer->queue($mailable);

            $log->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $log->forceFill(['status' => 'failed'])->save();

            Log::warning('Bank transfer proof notification email failed.', [
                'payment_id' => $payment->id,
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
