<?php

namespace App\Support\Finance;

class AmountInWords
{
    /**
     * @var array<int, string>
     */
    private const ONES = [
        0 => 'Zero',
        1 => 'One',
        2 => 'Two',
        3 => 'Three',
        4 => 'Four',
        5 => 'Five',
        6 => 'Six',
        7 => 'Seven',
        8 => 'Eight',
        9 => 'Nine',
        10 => 'Ten',
        11 => 'Eleven',
        12 => 'Twelve',
        13 => 'Thirteen',
        14 => 'Fourteen',
        15 => 'Fifteen',
        16 => 'Sixteen',
        17 => 'Seventeen',
        18 => 'Eighteen',
        19 => 'Nineteen',
    ];

    /**
     * @var array<int, string>
     */
    private const TENS = [
        2 => 'Twenty',
        3 => 'Thirty',
        4 => 'Forty',
        5 => 'Fifty',
        6 => 'Sixty',
        7 => 'Seventy',
        8 => 'Eighty',
        9 => 'Ninety',
    ];

    public static function fromCents(int $amountCents, string $currency = 'ZMW'): string
    {
        $major = intdiv(max(0, $amountCents), 100);
        $minor = max(0, $amountCents) % 100;
        $majorLabel = strtoupper($currency) === 'ZMW' ? 'Kwacha' : $currency;

        $words = self::numberToWords($major).' '.$majorLabel;
        if ($minor > 0) {
            $words .= ' and '.self::numberToWords($minor).' Ngwee';
        } else {
            $words .= ' Only';
        }

        return $words;
    }

    private static function numberToWords(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number];
        }

        if ($number < 100) {
            $ten = intdiv($number, 10);
            $one = $number % 10;

            return self::TENS[$ten].($one > 0 ? ' '.self::ONES[$one] : '');
        }

        if ($number < 1000) {
            $hundreds = intdiv($number, 100);
            $remainder = $number % 100;

            return self::ONES[$hundreds].' Hundred'.($remainder > 0 ? ' '.self::numberToWords($remainder) : '');
        }

        if ($number < 1_000_000) {
            $thousands = intdiv($number, 1000);
            $remainder = $number % 1000;

            return self::numberToWords($thousands).' Thousand'.($remainder > 0 ? ' '.self::numberToWords($remainder) : '');
        }

        $millions = intdiv($number, 1_000_000);
        $remainder = $number % 1_000_000;

        return self::numberToWords($millions).' Million'.($remainder > 0 ? ' '.self::numberToWords($remainder) : '');
    }
}
