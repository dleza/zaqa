<?php

namespace App\Domain\Finance\Listeners;

use App\Domain\Finance\Events\PaymentProofSubmitted;
use App\Domain\Notifications\OutboundMailService;
use App\Mail\Finance\BankTransferProofSubmittedMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendBankTransferProofSubmittedNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(PaymentProofSubmitted $event): void
    {
        $mail = app(OutboundMailService::class);
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

        $mail->queue(
            mailable: new BankTransferProofSubmittedMail(
                payment: $payment,
                application: $application,
                applicant: $applicant,
                proofDocument: $proof,
                financeReviewUrl: route('admin.finance.payment_proofs.show', ['payment' => $payment->id]),
                attachProof: $attachProof,
            ),
            to: (string) $to,
            logContext: [
                'user_id' => null,
                'application_id' => $application->id,
                'email' => (string) $to,
                'subject' => 'ZAQA: Bank transfer proof submitted',
                'template_key' => 'finance_payment_proof_submitted',
            ],
            cc: $cc,
        );
    }
}
