<?php

namespace App\Support\Pdf;

use App\Domains\Organizations\Models\Organization;

/**
 * The footer line of generated PDFs: the organisation's own text
 * (Settings > Invoice > PDF footer, `:year` = current year) or the default.
 */
final class PdfFooter
{
    public static function text(?Organization $organization): string
    {
        $text = trim((string) $organization?->pdf_footer_text);

        return $text === '' ? '© '.now()->year.' Gäld' : str_replace(':year', (string) now()->year, $text);
    }
}
