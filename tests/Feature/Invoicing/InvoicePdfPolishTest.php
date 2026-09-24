<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\GenerateQrInvoicePdfAction;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Support\InvoicePdfStyle;
use App\Domains\Invoicing\Support\PaymentTerms;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The standard invoice PDF: the logo keeps its shape and never overlaps the
 * sender block, payment terms in days get their unit, the footer line is the
 * organisation's own text, and the introduction is printed before the lines.
 */
class InvoicePdfPolishTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->org = Organization::create([
            'name' => 'Test GmbH', 'legal_name' => 'Test GmbH', 'address' => 'Bahnhofstrasse 1',
            'postal_code' => '8001', 'city' => 'Zürich', 'country' => 'CH', 'currency' => 'CHF',
        ]);
        BankAccount::create([
            'organization_id' => $this->org->id, 'name' => 'QR-Bill account', 'currency' => 'CHF',
            'qr_iban' => 'CH4431999123000889012', 'is_default_for_invoicing' => true, 'is_active' => true,
        ]);
        $customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Client AG', 'address' => 'Lagerstrasse 5', 'postal_code' => '8004', 'city' => 'Zürich', 'country' => 'CH']);
        $this->invoice = Invoice::create([
            'organization_id' => $this->org->id, 'customer_id' => $customer->id, 'number' => 'INV-POLISH-001', 'status' => 'sent',
            'issue_date' => '2026-06-01', 'due_date' => '2026-07-01', 'subtotal' => '100.00', 'vat_amount' => '0.00', 'total' => '100.00', 'currency' => 'CHF',
        ]);
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function logoShapes(): array
    {
        return ['wide' => [400, 100], 'square' => [800, 800], 'tall' => [200, 600]];
    }

    #[DataProvider('logoShapes')]
    public function test_the_logo_keeps_its_shape_and_the_sender_block_starts_below_it(int $width, int $height): void
    {
        $this->org->update(['logo_path' => $this->logo($width, $height)]);

        $page = $this->firstPage($this->render());

        // TCPDF draws an image as "q w 0 0 h x y cm /I... Do Q" (points, origin bottom left).
        $this->assertSame(1, preg_match('#q ([\d.]+) 0 0 ([\d.]+) ([\d.]+) ([\d.]+) cm /I\d+ Do Q#', $page, $image), 'The logo is drawn');
        [, $w, $h, , $bottom] = array_map('floatval', $image);
        $this->assertEqualsWithDelta($width / $height, $w / $h, 0.01, 'The aspect ratio is kept');
        $this->assertLessThanOrEqual(InvoicePdfStyle::LOGO_WIDTH * 72 / 25.4 + 0.1, $w);
        $this->assertLessThanOrEqual(InvoicePdfStyle::LOGO_MAX_HEIGHT * 72 / 25.4 + 0.1, $h);

        $this->assertSame(1, preg_match('#BT ([\d.]+) ([\d.]+) Td \[\(Test GmbH\)\] TJ ET#', $page, $name), 'The sender name is printed');
        $this->assertLessThan($bottom, (float) $name[2] + 10, 'The sender name starts below the logo');
        // The sender block starts at 30 mm as before for a wide logo, below the box for the others.
        $nameTopMm = (841.89 - (float) $name[2]) * 25.4 / 72;
        $height >= $width ? $this->assertGreaterThan(39, $nameTopMm) : $this->assertEqualsWithDelta(34, $nameTopMm, 3);
    }

    public function test_payment_terms_in_days_get_their_unit_and_free_text_is_kept(): void
    {
        $this->assertSame('30 jours', PaymentTerms::label('30', 'fr'));
        $this->assertSame('30 Tage', PaymentTerms::label(' 30 ', 'de'));
        $this->assertSame('30 giorni', PaymentTerms::label('30', 'it'));
        $this->assertSame('30 days', PaymentTerms::label('30', 'en'));
        $this->assertSame('30 jours net', PaymentTerms::label('30 jours net', 'fr'));
        $this->assertNull(PaymentTerms::label('', 'fr'));
        $this->assertNull(PaymentTerms::label(null, 'fr'));

        $this->invoice->update(['payment_terms' => '30']);
        $this->assertStringContainsString('30 jours', $this->firstPage($this->render('fr')));
    }

    public function test_the_footer_is_the_default_text_or_the_organisation_s_own(): void
    {
        $this->assertStringContainsString(now()->year.' G', $this->firstPage($this->render()));

        $this->org->update(['pdf_footer_text' => 'Test GmbH - Bahnhofstrasse 1 - :year']);
        $page = $this->firstPage($this->render());

        $this->assertStringContainsString('Test GmbH - Bahnhofstrasse 1 - '.now()->year, $page);
        $this->assertStringNotContainsString(now()->year.' G', $page);
    }

    public function test_the_shared_export_footer_prints_the_organisation_text(): void
    {
        $this->assertStringContainsString('© '.now()->year.' Gäld', view('exports._footer', ['organization' => $this->org])->render());

        $this->org->update(['pdf_footer_text' => 'Test GmbH <b>:year</b>']);
        $html = view('exports._footer', ['organization' => $this->org->fresh()])->render();

        $this->assertStringContainsString('Test GmbH &lt;b&gt;'.now()->year.'&lt;/b&gt;', $html);
    }

    public function test_the_introduction_is_printed_before_the_line_items(): void
    {
        $this->invoice->update(['introduction' => 'Mandat de conseil, juin 2026']);
        $this->invoice->lines()->create(['description' => 'Consulting', 'quantity' => 1, 'unit_price' => '100.00', 'amount' => '100.00', 'vat_amount' => '0.00', 'sort_order' => 0]);

        $page = $this->firstPage($this->render());

        $intro = strpos($page, '(Mandat de conseil, juin 2026)');
        $line = strpos($page, '(Consulting)');
        $this->assertNotFalse($intro);
        $this->assertNotFalse($line);
        $this->assertLessThan($line, $intro);
    }

    private function logo(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 90, 70));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        Storage::disk('local')->put("logos/test-{$width}x{$height}.png", $png);

        return "logos/test-{$width}x{$height}.png";
    }

    private function render(string $language = 'fr'): string
    {
        return app(GenerateQrInvoicePdfAction::class)->execute($this->invoice->fresh(), $this->org->fresh(), $language);
    }

    /** The decompressed content stream of the first page. */
    private function firstPage(string $pdf): string
    {
        preg_match('#/Type\s*/Page[^s].*?/Contents\s+(\d+)\s+0\s+R#s', $pdf, $pages);
        preg_match('#\n'.$pages[1].' 0 obj\s*<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream#s', $pdf, $match);

        return str_contains($match[1], 'FlateDecode') ? (string) gzuncompress($match[2]) : $match[2];
    }
}
