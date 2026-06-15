<?php

namespace App\Http\Controllers;

use App\Domain\Finance\PaymentReceiptPdfService;
use App\Enums\PaymentStatus;
use App\Models\ApplicantProfile;
use App\Models\Payment;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ReceiptVerificationController extends Controller
{
    public function show(string $token, PaymentReceiptPdfService $receipts)
    {
        $payment = Payment::query()
            ->with([
                'application.applicant.applicantProfile',
                'invoice:id,invoice_number',
            ])
            ->where('public_receipt_token', $token)
            ->first();

        if (! $payment || ! $receipts->isEligible($payment)) {
            return Inertia::render('Receipts/Verify', [
                'verification' => [
                    'found' => false,
                    'status' => 'not_found',
                    'status_label' => 'Invalid Receipt',
                    'message' => 'We could not verify this receipt. The code may be invalid or the payment is not confirmed.',
                    'receipt' => null,
                ],
            ])->toResponse(request())->setStatusCode(Response::HTTP_NOT_FOUND);
        }

        $application = $payment->application;
        $applicant = $application?->applicant;
        /** @var ApplicantProfile|null $profile */
        $profile = $applicant?->applicantProfile;

        $holderName = $this->holderName($application, $applicant, $profile);
        $confirmedAt = $payment->confirmed_at ?? $payment->reviewed_at ?? $payment->created_at;
        $confirmedAt = $confirmedAt?->timezone(config('app.timezone'));

        return Inertia::render('Receipts/Verify', [
            'verification' => [
                'found' => true,
                'status' => 'verified',
                'status_label' => 'Receipt Verified',
                'message' => 'This is an official electronic receipt from the Zambia Qualifications Authority.',
                'verified_at' => now()->toIso8601String(),
                'receipt' => [
                    'receipt_number' => $receipts->receiptNumber($payment),
                    'receipt_number_display' => 'ZQ '.$payment->id,
                    'payment_status' => PaymentStatus::Confirmed->value,
                    'payment_status_label' => 'Confirmed',
                    'payment_date' => $confirmedAt?->toIso8601String(),
                    'amount_cents' => (int) $payment->amount_cents,
                    'currency' => (string) ($payment->currency ?: 'ZMW'),
                    'application_reference' => $application?->application_number,
                    'invoice_number' => $payment->invoice?->invoice_number,
                    'holder_name' => $holderName !== '' ? $holderName : null,
                    'payment_method' => ucwords(str_replace('_', ' ', $payment->method?->value ?? (string) $payment->method)),
                ],
            ],
        ]);
    }

    private function holderName($application, $applicant, ?ApplicantProfile $profile): string
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
}
