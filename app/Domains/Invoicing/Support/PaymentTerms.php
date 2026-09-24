<?php

namespace App\Domains\Invoicing\Support;

/**
 * Payment terms as printed: a number of days gets its unit ("30 jours"),
 * any other text is printed as entered ("30 jours net").
 */
final class PaymentTerms
{
    public static function label(?string $terms, string $locale): ?string
    {
        $terms = trim((string) $terms);
        if ($terms === '') {
            return null;
        }

        return preg_match('/\A\d{1,9}\z/', $terms) === 1
            ? (string) trans_choice('app.days_count', (int) $terms, [], $locale)
            : $terms;
    }
}
