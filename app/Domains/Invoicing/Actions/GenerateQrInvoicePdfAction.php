<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\Exceptions\QrBillValidationException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoicePdfRenderer;
use App\Domains\Invoicing\Services\SwissQrInvoiceService;
use App\Domains\Invoicing\Support\InvoicePdfStyle;
use App\Domains\Organizations\Models\Organization;
use App\Support\Pdf\PdfLayouts;
use Sprain\SwissQrBill\PaymentPart\Output\DisplayOptions;
use Sprain\SwissQrBill\PaymentPart\Output\TcPdfOutput\TcPdfOutput;
use TCPDF;

class GenerateQrInvoicePdfAction
{
    public function __construct(
        private SwissQrInvoiceService $qrService,
        private InvoicePdfRenderer $pdfRenderer,
        private PdfLayouts $layouts,
    ) {}

    /**
     * Generate a PDF invoice with Swiss QR payment slip.
     *
     * Returns raw PDF binary string.
     */
    public function execute(Invoice $invoice, Organization $organization, string $language = 'en'): string
    {
        $invoice->loadMissing(['customer', 'lines.vatRate']);

        // Validate the QR-bill first: nothing is rendered for an invalid bill, and a
        // missing QR reference is assigned before the invoice pages print it.
        $violations = $this->qrService->validate($invoice, $organization);
        if ($violations !== []) {
            throw new QrBillValidationException($violations);
        }

        $tcpdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $tcpdf->setPrintHeader(false);
        $tcpdf->setPrintFooter(false);
        $tcpdf->SetMargins(InvoicePdfStyle::MARGIN_LEFT, InvoicePdfStyle::MARGIN_TOP, InvoicePdfStyle::MARGIN_RIGHT);
        $tcpdf->SetAutoPageBreak(false);

        $layout = $this->layouts->invoice($organization);
        if ($layout !== null) {
            // A layout chosen by the organisation. The payment part is drawn first, on the
            // fresh document, so nothing the layout does to the drawing state can move or
            // restyle it (Swiss Payment Standards); its page then goes to the end (TCPDF
            // moves pages only forward: each layout page moves up by one).
            $this->addPaymentPage($tcpdf, $invoice, $organization, $language);
            $tcpdf->startPageGroup(); // the layout's pages: getGroupPageNo() / getPageGroupAlias()
            $this->addPage($tcpdf);
            $layout->renderInvoice($tcpdf, $invoice, $organization, $language);
            for ($page = 2, $pages = $tcpdf->getNumPages(); $page <= $pages; $page++) {
                $tcpdf->movePage($page, $page - 1);
            }

            return $tcpdf->Output('', 'S');
        }

        // --- INVOICE CONTENT ---
        $this->addPage($tcpdf);
        $this->pdfRenderer->setLocale($language);
        $this->pdfRenderer->renderFoldMarks($tcpdf);
        $this->pdfRenderer->renderInvoiceHeader($tcpdf, $invoice, $organization);
        $this->pdfRenderer->renderLineItems($tcpdf, $invoice);
        $this->pdfRenderer->renderTotals($tcpdf, $invoice, $organization);
        $this->pdfRenderer->renderFooter($tcpdf);

        // --- QR PAYMENT SLIP (dedicated last page) ---
        $this->addPaymentPage($tcpdf, $invoice, $organization, $language);

        return $tcpdf->Output('', 'S');
    }

    private function addPage(TCPDF $tcpdf): void
    {
        $tcpdf->AddPage();
        $tcpdf->SetFillColor(255, 255, 255);
        $tcpdf->Rect(0, 0, $tcpdf->getPageWidth(), $tcpdf->getPageHeight(), 'F');
    }

    /** The QR payment slip + receipt on a page of its own. */
    private function addPaymentPage(TCPDF $tcpdf, Invoice $invoice, Organization $organization, string $language): void
    {
        $qrBill = $this->qrService->buildQrBill($invoice, $organization);
        $langMap = ['en' => 'en', 'de' => 'de', 'fr' => 'fr', 'it' => 'it', 'rm' => 'de'];
        $qrLang = $langMap[$language] ?? 'en';

        $this->addPage($tcpdf);
        // The payment part sets fonts and positions but inherits the text colour: black.
        $tcpdf->SetTextColor(0, 0, 0);

        $output = new TcPdfOutput($qrBill, $qrLang, $tcpdf);
        $displayOptions = (new DisplayOptions)->setPrintable(false);
        $output->setDisplayOptions($displayOptions)->getPaymentPart();
    }
}
