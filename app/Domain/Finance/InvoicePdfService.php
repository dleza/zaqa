<?php

namespace App\Domain\Finance;

use App\Enums\InvoiceStatus;
use App\Models\ApplicantProfile;
use App\Models\Invoice;
use App\Models\Qualification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InvoicePdfService
{
    /**
     * @return array<string, mixed>
     */
    public function buildViewData(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'application.applicant.applicantProfile',
            'application.qualifications',
        ]);

        $application = $invoice->application;
        $applicant = $application?->applicant;
        /** @var ApplicantProfile|null $profile */
        $profile = $applicant?->applicantProfile;

        $lineItems = $this->lineItems($invoice);
        $subtotalCents = (int) $lineItems->sum('total_cents');
        $vatCents = (int) data_get($invoice->metadata, 'vat_cents', 0);
        $discountCents = (int) data_get($invoice->metadata, 'discount_cents', 0);
        $totalCents = (int) $invoice->amount_cents;

        return [
            'logo_data_uri' => $this->logoDataUri(),
            'organization' => config('zaqa.organization', []),
            'bill_to' => [
                'name' => $this->billToName($application, $applicant, $profile),
                'address' => $this->billToAddress($profile),
                'phone' => trim((string) ($profile?->phone_primary ?? $applicant?->phone_primary ?? '')),
                'email' => trim((string) ($profile?->email ?? $applicant?->email ?? '')),
            ],
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => optional($invoice->issued_at)?->timezone(config('app.timezone'))->format('d/m/Y'),
            'status_label' => $this->statusLabel($invoice),
            'application_reference' => $application?->application_number,
            'application_id' => $application?->id,
            'currency' => (string) ($invoice->currency ?: 'ZMW'),
            'line_items' => $lineItems->all(),
            'subtotal_cents' => $subtotalCents,
            'vat_cents' => $vatCents,
            'discount_cents' => $discountCents,
            'total_cents' => $totalCents,
            'vat_rate_label' => data_get($invoice->metadata, 'vat_rate_label', '16 %'),
            'discount_rate_label' => data_get($invoice->metadata, 'discount_rate_label', '0 %'),
        ];
    }

    /**
     * PDF-aligned payload for on-screen invoice preview (excludes embedded logo binary).
     *
     * @return array<string, mixed>
     */
    public function buildWebViewData(Invoice $invoice): array
    {
        $data = $this->buildViewData($invoice);
        unset($data['logo_data_uri']);

        return $data;
    }

    public function renderBinary(Invoice $invoice): string
    {
        $data = $this->buildViewData($invoice);

        return Pdf::loadView('pdf.invoice', $data)
            ->setPaper('a4', 'portrait')
            ->output();
    }

    public function downloadResponse(Invoice $invoice): Response
    {
        $binary = $this->renderBinary($invoice);
        $filename = $this->filename($invoice);

        return response($binary, SymfonyResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    public function filename(Invoice $invoice): string
    {
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->invoice_number) ?? 'invoice';
        $slug = trim((string) $slug, '-');

        return 'invoice-'.($slug !== '' ? $slug : $invoice->id).'.pdf';
    }

    /**
     * @return Collection<int, array{description: string, quantity: int, amount_cents: int, total_cents: int}>
     */
    private function lineItems(Invoice $invoice): Collection
    {
        $metadata = is_array($invoice->metadata) ? $invoice->metadata : [];
        $breakdown = is_array($metadata['breakdown'] ?? null) ? $metadata['breakdown'] : [];

        if ($breakdown !== []) {
            $qualifications = $invoice->application?->qualifications?->keyBy('id') ?? collect();
            $breakdownCount = count($breakdown);

            return collect($breakdown)->values()->map(function (array $line, int $index) use ($qualifications, $breakdownCount) {
                $qualificationId = (int) ($line['qualification_id'] ?? 0);
                /** @var Qualification|null $qualification */
                $qualification = $qualificationId > 0 ? $qualifications->get($qualificationId) : null;
                $title = trim((string) ($qualification?->title_of_qualification ?? ''));
                $feeLabel = trim((string) ($line['fee_label_snapshot'] ?? ''));

                $description = $title !== ''
                    ? 'Verification for '.$title
                    : ($feeLabel !== '' ? $feeLabel : 'Verification fee');

                if ($breakdownCount > 1) {
                    $description .= ' No '.($index + 1);
                }

                $amountCents = (int) ($line['amount_cents'] ?? 0);

                return [
                    'description' => $description,
                    'quantity' => 1,
                    'amount_cents' => $amountCents,
                    'total_cents' => $amountCents,
                ];
            });
        }

        $label = trim((string) ($invoice->fee_label_snapshot ?? ''));
        $amountCents = (int) $invoice->amount_cents;

        return collect([[
            'description' => $label !== '' ? $label : 'Verification fees',
            'quantity' => 1,
            'amount_cents' => $amountCents,
            'total_cents' => $amountCents,
        ]]);
    }

    private function statusLabel(Invoice $invoice): string
    {
        return match ($invoice->status) {
            InvoiceStatus::Paid => 'Paid',
            InvoiceStatus::Void => 'Cancelled',
            InvoiceStatus::Draft => 'Draft',
            InvoiceStatus::Issued => 'Pending',
            default => ucfirst((string) ($invoice->status?->value ?? $invoice->status ?? 'Pending')),
        };
    }

    private function billToName($application, $applicant, ?ApplicantProfile $profile): string
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

    private function billToAddress(?ApplicantProfile $profile): string
    {
        if (! $profile) {
            return '';
        }

        return trim(implode(' ', array_filter([
            $profile->address_line_1,
            $profile->address_line_2,
            $profile->city,
            $profile->province,
            $profile->postal_code,
            $profile->country,
        ], fn ($part) => trim((string) $part) !== '')));
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
}
