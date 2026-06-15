<?php

namespace App\Domain\Finance;

use App\Models\Payment;
use Illuminate\Support\Str;

class PaymentReceiptTokenService
{
    public function ensureToken(Payment $payment): string
    {
        $existing = trim((string) $payment->public_receipt_token);
        if ($existing !== '') {
            return $existing;
        }

        do {
            $token = Str::random(48);
        } while (Payment::query()->where('public_receipt_token', $token)->exists());

        $payment->forceFill(['public_receipt_token' => $token])->save();

        return $token;
    }

    public function verificationUrl(Payment $payment): string
    {
        $token = $this->ensureToken($payment);

        return config('zaqa.receipt.verify_url_base').'/'.$token;
    }
}
