<?php

namespace App\Support\Pdf;

/**
 * A layout for a generated PDF document (e.g. the invoice or the salary
 * slip), registered in PdfLayouts by a plugin. An organisation chooses it in
 * its settings; without a choice, core's standard layout is used.
 */
interface PdfLayoutInterface
{
    /** Stored in the organisation's settings (short, stable, e.g. `classic`). */
    public function key(): string;

    /** Name shown in the settings (translated). */
    public function label(): string;
}
