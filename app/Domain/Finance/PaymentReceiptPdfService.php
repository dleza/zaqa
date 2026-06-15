<?php

namespace App\Domain\Finance;

use App\Domain\Settings\DocumentSignatureService;
use App\Enums\DocumentSignatureType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\ApplicantProfile;
use App\Models\Payment;
use App\Support\Finance\AmountInWords;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PaymentReceiptPdfService
{
    public function __construct(
        private readonly PaymentReceiptTokenService $tokens,
        private readonly DocumentSignatureService $signatures,
    ) {}

    public function isEligible(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Confirmed;
    }

    public function receiptDownloadUrl(Payment $payment, string $routeName): ?string
    {
        return $this->isEligible($payment) ? route($routeName, $payment) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildViewData(Payment $payment): array
    {
        if (! $this->isEligible($payment)) {
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        $payment->loadMissing([
            'application.applicant.applicantProfile',
            'invoice',
        ]);

        $application = $payment->application;
        $applicant = $application?->applicant;
        /** @var ApplicantProfile|null $profile */
        $profile = $applicant?->applicantProfile;
        $applicantName = $this->applicantName($application, $applicant, $profile);

        $confirmedAt = $payment->confirmed_at ?? $payment->reviewed_at ?? $payment->created_at;
        $confirmedAt = $confirmedAt?->timezone(config('app.timezone'));

        $currency = (string) ($payment->currency ?: 'ZMW');
        $amountCents = (int) $payment->amount_cents;
        $breakdown = $this->paymentBreakdown($payment, $amountCents);

        $receiptNumber = $this->receiptNumber($payment);
        $reference = trim((string) ($payment->provider_reference
            ?: $payment->invoice?->invoice_number
            ?: $application?->application_number
            ?: ''));

        $description = $applicantName !== ''
            ? $applicantName.' - Application for Validation & Evaluation'
            : 'Application for Qualification Verification'.($application?->application_number ? ' - '.$application->application_number : '');

        $verificationUrl = $this->tokens->verificationUrl($payment);

        return [
            'logo_data_uri' => $this->logoDataUri(),
            'signature_data_uri' => $this->signatures->dataUriForType(DocumentSignatureType::Receipt),
            'organization' => config('zaqa.organization', []),
            'receipt_number' => $receiptNumber,
            'receipt_number_display' => 'ZQ '.$payment->id,
            'receipt_date' => $confirmedAt?->format('n/j/Y'),
            'receipt_time' => $confirmedAt?->format('g:i A'),
            'account_label' => $this->accountLabel($payment),
            'account_reference' => (string) ($payment->invoice_id ?: $payment->id),
            'description' => $description,
            'amount_in_words' => AmountInWords::fromCents($amountCents, $currency),
            'reference' => $reference !== '' ? $reference : '—',
            'currency' => $currency,
            'amount_cents' => $amountCents,
            'amount_formatted' => number_format($amountCents / 100, 2),
            'payment_method_label' => $this->methodLabel($payment),
            'application_reference' => $application?->application_number,
            'invoice_number' => $payment->invoice?->invoice_number,
            'breakdown' => $breakdown,
            'verification_url' => $verificationUrl,
            'qr_data_uri' => $this->buildQrDataUri($verificationUrl),
        ];
    }

    public function renderBinary(Payment $payment): string
    {
        return Pdf::loadView('pdf.payment-receipt', $this->buildViewData($payment))
            ->setPaper('a4', 'portrait')
            ->output();
    }

    public function downloadResponse(Payment $payment): Response
    {
        $binary = $this->renderBinary($payment);

        return response($binary, SymfonyResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($payment).'"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    public function filename(Payment $payment): string
    {
        return 'receipt-ZQ'.$payment->id.'.pdf';
    }

    public function receiptNumber(Payment $payment): string
    {
        return 'ZQ'.$payment->id;
    }

    /**
     * @return array{cheque_no: string, cheque_amount: string, cash_amount: string, electronic_amount: string, total: string}
     */
    private function paymentBreakdown(Payment $payment, int $amountCents): array
    {
        $formatted = number_format($amountCents / 100, 2);
        $method = $payment->method;

        $electronic = in_array($method, [
            PaymentMethod::Card,
            PaymentMethod::MobileMoney,
            PaymentMethod::BankTransfer,
            PaymentMethod::BankDeposit,
        ], true) ? $formatted : '0';

        return [
            'cheque_no' => '0.00',
            'cheque_amount' => '0',
            'cash_amount' => '0',
            'electronic_amount' => $electronic,
            'total' => 'K '.$formatted,
        ];
    }

    private function accountLabel(Payment $payment): string
    {
        return match ($payment->method) {
            PaymentMethod::MobileMoney => 'Mobile Money Account',
            PaymentMethod::Card => 'Card Account',
            PaymentMethod::BankTransfer, PaymentMethod::BankDeposit => 'Bank Account',
            default => 'CashAccount',
        };
    }

    private function methodLabel(Payment $payment): string
    {
        $value = $payment->method?->value ?? (string) $payment->method;

        return ucwords(str_replace('_', ' ', $value));
    }

    private function applicantName($application, $applicant, ?ApplicantProfile $profile): string
    {
        $meta = is_array($application?->metadata) ? $application->metadata : [];
        $subject = is_array($meta['verification_subject'] ?? null) ? $meta['verification_subject'] : [];
        $fromSubject = trim((string) ($subject['full_name'] ?? ''));
        if ($fromSubject !== '') {
            return $fromSubject;
        }

        if ($profile) {
            $composed = trim(implode(' ', array_filter([
                $profile->first_name,
                $profile->middle_name,
                $profile->surname,
            ], fn ($part) => trim((string) $part) !== '')));

            if ($composed !== '') {
                return $composed;
            }
        }

        return trim((string) ($applicant?->name ?? ''));
    }

    private function logoDataUri(): ?string
    {
        $path = resource_path('images/zaqa_logo_clean.png');
        if (! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($contents);
    }

    private function buildQrDataUri(string $url): ?string
    {
        if (trim($url) === '') {
            return null;
        }

        try {
            $result = Builder::create()
                ->writer(new PngWriter)
                ->data($url)
                ->size(140)
                ->margin(6)
                ->build();

            return $result->getDataUri();
        } catch (\Throwable) {
            return null;
        }
    }
}
