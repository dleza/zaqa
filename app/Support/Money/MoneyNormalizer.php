<?php

namespace App\Support\Money;

final class MoneyNormalizer
{
    /**
     * Parse a human-entered currency amount (e.g. "50", "50.25", "1,200.00")
     * into minor units (ngwee/cents).
     *
     * Returns null when input is null/empty.
     */
    public static function toMinorUnits(string|int|float|null $input): ?int
    {
        if ($input === null) {
            return null;
        }

        $raw = trim((string) $input);
        if ($raw === '') {
            return null;
        }

        // Allow grouping commas in UI input, but normalize them away.
        $raw = str_replace(',', '', $raw);

        // Strict currency-like format: digits + optional . + 1-2 decimals.
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) {
            throw new \InvalidArgumentException('Invalid currency amount format.');
        }

        [$whole, $decimals] = array_pad(explode('.', $raw, 2), 2, '');

        $wholeInt = (int) $whole;
        $dec = substr(str_pad($decimals, 2, '0'), 0, 2);
        $decInt = (int) $dec;

        // Use string-safe arithmetic (avoid floats).
        return ($wholeInt * 100) + $decInt;
    }

    /**
     * Format minor units for display, e.g. 120000 -> "ZMW 1,200.00".
     */
    public static function formatMinorUnits(?int $cents, string $currency = 'ZMW'): string
    {
        if ($cents === null) {
            return '—';
        }

        $amount = $cents / 100;

        return strtoupper($currency).' '.number_format($amount, 2, '.', ',');
    }

    /**
     * Convert minor units to a form-friendly decimal string, e.g. 5000 -> "50.00".
     */
    public static function minorUnitsToDecimalString(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        return number_format($cents / 100, 2, '.', '');
    }
}

