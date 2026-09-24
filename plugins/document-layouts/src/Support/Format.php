<?php

namespace Plugins\DocumentLayouts\Support;

final class Format
{
    /** Swiss format: 1'234.50 */
    public static function money(string $value): string
    {
        return number_format((float) $value, 2, '.', "'");
    }

    /** 2, 1.5, 0.25 (no trailing zeros) */
    public static function quantity(string $value): string
    {
        return self::trim(number_format((float) $value, 4, '.', "'"));
    }

    /** 5.3 %, 8.1 %, 0.735 % */
    public static function rate(string $value): string
    {
        return self::trim(number_format((float) $value, 4, '.', '')).' %';
    }

    /** CH93 0076 2011 6238 5295 7 */
    public static function iban(string $iban): string
    {
        return trim(chunk_split(strtoupper((string) preg_replace('/\s+/', '', $iban)), 4, ' '));
    }

    /** QR reference in blocks of five from the right, as on the payment part: 21 00000 00003 13947 14300 09017 */
    public static function reference(string $reference): string
    {
        return strrev(trim(chunk_split(strrev((string) preg_replace('/\s+/', '', $reference)), 5, ' ')));
    }

    /** One of the documents' languages (fr, de, it, en). */
    public static function language(?string $locale): string
    {
        $language = substr((string) $locale, 0, 2);

        return in_array($language, ['fr', 'de', 'it', 'en'], true) ? $language : 'fr';
    }

    private static function trim(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
