<?php

namespace App\Mail\Finance;

use App\Models\Application;
use App\Models\Payment;
use App\Models\QualificationDocument;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BankTransferProofSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly Application $application,
        public readonly User $applicant,
        public readonly ?QualificationDocument $proofDocument,
        public readonly string $financeReviewUrl,
        public readonly bool $attachProof = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ZAQA: Bank transfer proof submitted',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.finance.bank_transfer_proof_submitted',
            with: [
                'payment' => $this->payment,
                'application' => $this->application,
                'applicant' => $this->applicant,
                'proofDocument' => $this->proofDocument,
                'financeReviewUrl' => $this->financeReviewUrl,
                'attachProof' => $this->attachProof,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->attachProof || ! $this->proofDocument) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($this->proofDocument->disk, $this->proofDocument->path)
                ->as($this->proofDocument->original_name)
                ->withMime($this->proofDocument->mime_type ?: 'application/octet-stream'),
        ];
    }
}
