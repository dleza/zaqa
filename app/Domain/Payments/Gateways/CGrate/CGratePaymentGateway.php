<?php

namespace App\Domain\Payments\Gateways\CGrate;

use App\Domain\Payments\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Support\Phone\ZambiaMsisdnNormalizer;
use Illuminate\Support\Str;

final class CGratePaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly CGrateClient $client,
    ) {
    }

    public function providerKey(): string
    {
        return 'cgrate';
    }

    public function initiate(Payment $payment, PaymentMethod $method, array $payload): array
    {
        if (! (bool) config('cgrate.enabled')) {
            throw new CGrateException('cGrate payments are disabled.');
        }

        if ($method !== PaymentMethod::MobileMoney) {
            throw new CGrateException('cGrate gateway only supports Mobile Money in this system.');
        }

        $mobileRaw = (string) ($payload['mobile_number'] ?? '');
        $msisdn = ZambiaMsisdnNormalizer::normalizeForCGrate($mobileRaw, (string) config('cgrate.msisdn_format', 'local'));

        $paymentReference = trim((string) ($payload['payment_reference'] ?? ''));
        if ($paymentReference === '') {
            $paymentReference = $this->generatePaymentReference($payment);
        }

        $transactionAmount = $this->formatTransactionAmount((int) $payment->amount_cents);

        $resp = $this->client->processCustomerPayment(
            transactionAmount: $transactionAmount,
            customerMobile: $msisdn,
            paymentReference: $paymentReference,
        );

        return [
            'provider_reference' => $paymentReference,
            'provider_transaction_id' => $resp->paymentId,
            'raw_payload' => [
                'cgrate' => $resp->toArray(),
            ],
        ];
    }

    public function verify(string $providerReference, array $payload): array
    {
        if (! (bool) config('cgrate.enabled')) {
            throw new CGrateException('cGrate payments are disabled.');
        }

        $resp = $this->client->queryCustomerPayment($providerReference);

        $status = $this->normalizeQueryStatus($resp);

        return [
            'status' => $status,
            'provider_transaction_id' => $resp->paymentId,
            'raw_payload' => [
                'cgrate' => $resp->toArray(),
            ],
        ];
    }

    private function generatePaymentReference(Payment $payment): string
    {
        $invoiceId = (int) ($payment->invoice_id ?? 0);
        $paymentId = (int) $payment->id;

        $rand = Str::upper(Str::random(10));

        // Keep within typical PSP ref limits and ASCII-safe.
        return 'ZAQA-'.$invoiceId.'-'.$paymentId.'-'.$rand;
    }

    private function formatTransactionAmount(int $amountCents): string
    {
        $mode = (string) config('cgrate.amount_mode', 'kwacha_decimal');

        return '1.00';

        // return match ($mode) {
        //     'minor_units' => (string) $amountCents,
        //     'kwacha_decimal' => number_format($amountCents / 100, 2, '.', ''),
        //     default => throw new CGrateException('Invalid cGrate amount mode configuration.'),
        // };
    }

    private function normalizeQueryStatus(CGratePaymentResponse $resp): string
    {
        if ($resp->isApproved()) {
            return 'confirmed';
        }
        if ($resp->isRejected()) {
            return 'rejected';
        }
        if ($resp->isFailed()) {
            return 'failed';
        }
        if ($resp->isPending()) {
            return 'pending';
        }
        if ($resp->isUnknown()) {
            return 'unknown';
        }

        // Bare responseCode=0 on query (no paymentID) is not a final paid state.
        if ($resp->responseCode === 0) {
            return 'pending';
        }

        if ($resp->isConfigOrAuthError()) {
            return 'failed';
        }

        return 'unknown';
    }
}
