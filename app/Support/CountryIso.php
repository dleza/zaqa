<?php

namespace App\Support;

/**
 * Country ISO-3166 codes in this app use alpha-3 in `countries.iso_code` (e.g. ZMB for Zambia).
 * Some checks historically used alpha-2 (ZM) only, which mis-classified Zambian rows as foreign.
 */
final class CountryIso
{
    public static function isZambia(string $iso): bool
    {
        $u = strtoupper(trim($iso));

        return $u === 'ZM' || $u === 'ZMB';
    }
}
