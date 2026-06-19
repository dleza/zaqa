<?php

namespace App\Domain\Payments\Gateways\CyberSource;

final class CyberSourcePayloadSanitizer
{
    public function sanitize(array $payload): array
    {
        return $this->sanitizeArray($payload);
    }

    private function sanitizeArray(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);

                continue;
            }

            if (is_object($value)) {
                continue;
            }

            if (is_string($value) && $this->looksSensitive($value)) {
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $key) ?? '');

        return in_array($normalized, [
            'accountnumber',
            'card',
            'cardnumber',
            'cavv',
            'cryptogram',
            'cvn',
            'cvv',
            'cvc',
            'expirationdate',
            'expirationmonth',
            'expirationyear',
            'expiry',
            'expirydate',
            'expirymonth',
            'expiryyear',
            'expdate',
            'expmonth',
            'expyear',
            'fluiddata',
            'instrumentidentifier',
            'legacytoken',
            'number',
            'pan',
            'paymentinstrument',
            'securitycode',
            'securitycodeindicator',
            'thirdpartytoken',
            'tokenizedcard',
            'transienttoken',
            'transienttokenjwt',
        ], true);
    }

    private function looksSensitive(string $value): bool
    {
        $trimmed = trim($value);

        if (preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $trimmed) === 1) {
            return true;
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        return strlen($digits) >= 12 && strlen($digits) <= 19;
    }
}
