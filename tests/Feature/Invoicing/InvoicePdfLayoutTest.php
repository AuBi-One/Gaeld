<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\GenerateQrInvoicePdfAction;
use App\Domains\Invoicing\Contracts\InvoicePdfLayoutInterface;
use App\Domains\Invoicing\Exceptions\QrBillValidationException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceLine;
use App\Domains\Organizations\Models\Organization;
use App\Support\Pdf\PdfLayouts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use TCPDF;
use Tests\TestCase;

/**
 * A plugin layout draws the invoice pages; core keeps the Swiss QR-bill
 * payment part, identical whatever the layout does to the drawing state.
 */
class InvoicePdfLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Test GmbH',
            'legal_name' => 'Test GmbH',
            'address' => 'Bahnhofstrasse 1',
            'postal_code' => '8001',
            'city' => 'Zürich',
            'country' => 'CH',
            'currency' => 'CHF',
        ]);
        BankAccount::create([
            'organization_id' => $this->org->id,
            'name' => 'QR-Bill account',
            'currency' => 'CHF',
            'qr_iban' => 'CH4431999123000889012',
            'is_default_for_invoicing' => true,
            'is_active' => true,
        ]);
        $customer = Contact::create([
            'organization_id' => $this->org->id,
            'name' => 'Client AG',
            'address' => 'Lagerstrasse 5',
            'postal_code' => '8004',
            'city' => 'Zürich',
            'country' => 'CH',
        ]);
        $vat = VatRate::create(['organization_id' => $this->org->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->invoice = Invoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $customer->id,
            'number' => 'INV-LAYOUT-001',
            'status' => 'sent',
            'issue_date' => '2026-06-01',
            'due_date' => '2026-07-01',
            'subtotal' => '1000.00',
            'vat_amount' => '81.00',
            'total' => '1081.00',
            'currency' => 'CHF',
        ]);
        InvoiceLine::create([
            'invoice_id' => $this->invoice->id,
            'description' => 'Consulting',
            'quantity' => 10,
            'unit_price' => '100.00',
            'amount' => '1000.00',
            'vat_rate_id' => $vat->id,
            'vat_amount' => '81.00',
            'sort_order' => 0,
        ]);
    }

    public function test_without_a_chosen_layout_the_standard_layout_is_used(): void
    {
        $layout = $this->registerLayout();

        $pdf = $this->render();

        $this->assertSame(0, $layout->calls);
        $this->assertSame(2, $this->pageCount($pdf));
    }

    public function test_the_chosen_layout_draws_the_invoice_pages_and_core_adds_the_qr_bill_page(): void
    {
        $layout = $this->registerLayout(pages: 2);
        $this->org->update(['pdf_layouts' => [PdfLayouts::INVOICE => 'test-layout']]);

        $pdf = $this->render();

        $this->assertSame(1, $layout->calls);
        $this->assertSame('fr', $layout->language);
        $this->assertSame(3, $this->pageCount($pdf), 'Two layout pages + the QR-bill page');
        $this->assertStringContainsString('(Invoice INV-LAYOUT-001)', $this->pageContent($pdf, 0), 'The layout pages come first');
        $this->assertStringContainsString('(Section paiement)', $this->pageContent($pdf, -1), 'The payment part is the last page');
        // The QR reference is assigned before the layout runs, so a layout can print it.
        $this->assertNotNull($layout->qrReference);
    }

    public function test_the_payment_part_is_identical_whatever_state_the_layout_leaves(): void
    {
        $standard = $this->lastPageContent($this->render());

        $this->registerLayout(mangle: true);
        $this->org->update(['pdf_layouts' => [PdfLayouts::INVOICE => 'test-layout']]);
        $pdf = $this->render();
        $withLayout = $this->lastPageContent($pdf);

        $this->assertNotSame('', $standard);
        $this->assertSame($standard, $withLayout);
        $this->assertStringEndsWith('/MediaBox [0.000000 0.000000 595.276000 841.890000]', $this->lastPageMediaBox($pdf), 'A4 portrait');
    }

    public function test_a_chosen_layout_that_is_no_longer_registered_falls_back_to_the_standard_layout(): void
    {
        $this->org->update(['pdf_layouts' => [PdfLayouts::INVOICE => 'removed-plugin']]);

        $this->assertNull(app(PdfLayouts::class)->invoice($this->org));
        $this->assertSame(2, $this->pageCount($this->render()));
    }

    public function test_an_invalid_qr_bill_fails_before_the_layout_is_called(): void
    {
        $layout = $this->registerLayout();
        $this->org->update(['pdf_layouts' => [PdfLayouts::INVOICE => 'test-layout']]);
        $this->invoice->update(['total' => '-10.00']);

        try {
            $this->render();
            $this->fail('Expected a QR-bill validation error');
        } catch (QrBillValidationException) {
            $this->assertSame(0, $layout->calls);
        }
    }

    private function registerLayout(int $pages = 1, bool $mangle = false): InvoicePdfLayoutInterface
    {
        $layout = new class($pages, $mangle) implements InvoicePdfLayoutInterface
        {
            public int $calls = 0;

            public ?string $language = null;

            public ?string $qrReference = null;

            public function __construct(private int $pages, private bool $mangle) {}

            public function key(): string
            {
                return 'test-layout';
            }

            public function label(): string
            {
                return 'Test layout';
            }

            public function renderInvoice(TCPDF $pdf, Invoice $invoice, Organization $organization, string $language): void
            {
                $this->calls++;
                $this->language = $language;
                $this->qrReference = $invoice->qr_reference;
                $pdf->SetFont('helvetica', 'B', 20);
                $pdf->Text(20, 20, 'Invoice '.$invoice->number);
                for ($i = 1; $i < $this->pages; $i++) {
                    $pdf->AddPage();
                }
                if ($this->mangle) {
                    $pdf->AddPage('L', 'A5');
                    $pdf->setPageUnit('pt');
                    $pdf->setTextRenderingMode(1);
                    $pdf->setTextShadow(['enabled' => true]);
                    $pdf->setRTL(true);
                    $pdf->SetAlpha(0.3);
                    $pdf->setCellMargins(3, 3, 3, 3);
                    $pdf->setFontStretching(80);
                    $pdf->SetLineStyle(['cap' => 'round', 'dash' => '3']);
                    $pdf->SetMargins(40, 60, 40);
                    $pdf->setCellPaddings(5, 5, 5, 5);
                    $pdf->setCellHeightRatio(2.5);
                    $pdf->SetTextColor(200, 0, 0);
                    $pdf->SetDrawColor(0, 0, 200);
                    $pdf->SetLineWidth(2);
                    $pdf->SetAutoPageBreak(true, 50);
                    $pdf->setPrintFooter(true);
                    $pdf->setFontSpacing(1);
                }
            }
        };
        app(PdfLayouts::class)->register($layout);

        return $layout;
    }

    private function render(): string
    {
        return app(GenerateQrInvoicePdfAction::class)->execute($this->invoice->fresh(), $this->org->fresh(), 'fr');
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    /** The decompressed content stream of a page (0 = first, -1 = last). */
    private function pageContent(string $pdf, int $index): string
    {
        preg_match_all('#/Type\s*/Page[^s].*?/Contents\s+(\d+)\s+0\s+R#s', $pdf, $pages);
        $object = (string) (array_slice($pages[1], $index, 1)[0] ?? '');
        $this->assertNotSame('', $object);
        preg_match('#\n'.$object.' 0 obj\s*<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream#s', $pdf, $match);

        return str_contains($match[1], 'FlateDecode') ? (string) gzuncompress($match[2]) : $match[2];
    }

    private function lastPageMediaBox(string $pdf): string
    {
        preg_match_all('#/Type\s*/Page[^s].*?(/MediaBox\s*\[[^\]]*\])#s', $pdf, $boxes);

        return (string) preg_replace('#\s+\]$#', ']', (string) end($boxes[1]));
    }

    /**
     * The drawing operations of the last page, without the graphics-state lines TCPDF
     * writes at the page start and before each cell (width, cap, join, dash, colours,
     * current font: no drawing). Every text, line and image of the payment part sets its
     * own position, font and colour; only the line cap of the separation lines is
     * inherited (sprain sets width, dash and colour), square after the standard layout,
     * TCPDF's default butt on the fresh document a layout gets. Font names normalised;
     * TCPDF's invisible producer mark removed.
     */
    private function lastPageContent(string $pdf): string
    {
        $content = $this->pageContent($pdf, -1);

        $content = (string) preg_replace([
            '#^[\d.]+ w \d J \d j \[[^\]]*\] [\d.]+ d .*$#m',
            '#^BT /F\d+ [\d.]+ Tf ET$#m',
            // TCPDF's invisible producer mark, written on the last page at output time.
            '#^.*\(Powered by TCPDF .*$#m',
        ], '', $content);

        return (string) preg_replace('#/F\d+ #', '/F ', $content);
    }
}
