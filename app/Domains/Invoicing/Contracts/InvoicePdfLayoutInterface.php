<?php

namespace App\Domains\Invoicing\Contracts;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Models\Organization;
use App\Support\Pdf\PdfLayoutInterface;
use TCPDF;

/**
 * Draws the invoice pages (letterhead, addresses, lines, totals, texts) in
 * place of core's standard layout.
 *
 * Core keeps the Swiss QR-bill: it validates the bill before the layout is
 * called and draws the payment part on a fresh document before the layout
 * runs, then moves that page to the end. The layout cannot move or restyle
 * the payment part through the drawing state; it must not select, change or
 * delete the payment page (page 1 while it runs: the first invoice page is
 * page 2; the invoice pages form a page group, so use getGroupPageNo() and
 * getPageGroupAlias() for page numbers).
 */
interface InvoicePdfLayoutInterface extends PdfLayoutInterface
{
    /**
     * `$pdf` is an A4 portrait TCPDF document (mm) whose current page is an empty
     * page for the invoice, with no automatic page break. Add pages as needed;
     * do not add the QR-bill.
     */
    public function renderInvoice(TCPDF $pdf, Invoice $invoice, Organization $organization, string $language): void;
}
