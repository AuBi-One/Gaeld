<?php

namespace Tests\Feature\Organizations;

use App\Domains\Invoicing\Contracts\InvoicePdfLayoutInterface;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use App\Support\Pdf\PdfLayoutInterface;
use App\Support\Pdf\PdfLayouts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use TCPDF;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class PdfLayoutSettingsTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    private function registerInvoiceLayout(): void
    {
        app(PdfLayouts::class)->register(new class implements InvoicePdfLayoutInterface
        {
            public function key(): string
            {
                return 'classic';
            }

            public function label(): string
            {
                return 'Classic';
            }

            public function renderInvoice(TCPDF $pdf, Invoice $invoice, Organization $organization, string $language): void {}
        });
    }

    public function test_the_settings_page_lists_only_documents_with_layouts(): void
    {
        $this->actAsOrg()->get(route('settings'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pdfLayouts', []));

        $this->registerInvoiceLayout();
        $this->org->update(['pdf_layouts' => ['invoice' => 'classic']]);

        $this->actAsOrg()->get(route('settings'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pdfLayouts', [[
                    'document' => 'invoice',
                    'options' => [['key' => 'classic', 'label' => 'Classic']],
                    'current' => 'classic',
                ]]));
    }

    public function test_a_registered_layout_can_be_chosen_and_reset_to_standard(): void
    {
        $this->registerInvoiceLayout();

        $this->actAsOrg()->put(route('settings.pdf-layouts'), ['pdf_layouts' => ['invoice' => 'classic']])
            ->assertRedirect(route('settings'));
        $this->assertSame(['invoice' => 'classic'], $this->org->fresh()->pdf_layouts);

        $this->actAsOrg()->put(route('settings.pdf-layouts'), ['pdf_layouts' => ['invoice' => '']])
            ->assertRedirect(route('settings'));
        $this->assertNull($this->org->fresh()->pdf_layouts);
    }

    public function test_unknown_layouts_and_documents_are_refused(): void
    {
        $this->registerInvoiceLayout();

        $this->actAsOrg()->put(route('settings.pdf-layouts'), ['pdf_layouts' => ['invoice' => 'nope']])
            ->assertSessionHasErrors('pdf_layouts.invoice');
        // A salary slip layout of that key is not registered.
        $this->actAsOrg()->put(route('settings.pdf-layouts'), ['pdf_layouts' => ['salary_slip' => 'classic']])
            ->assertSessionHasErrors('pdf_layouts.salary_slip');
        $this->actAsOrg()->put(route('settings.pdf-layouts'), ['pdf_layouts' => ['quote' => 'classic']])
            ->assertSessionHasErrors('pdf_layouts');
        $this->assertNull($this->org->fresh()->pdf_layouts);
    }

    public function test_members_who_may_not_edit_the_organisation_cannot_choose(): void
    {
        $this->registerInvoiceLayout();
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->org->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->org, 'viewer');

        $this->actingAs($viewer)->withSession(['current_organization_id' => $this->org->id])
            ->put(route('settings.pdf-layouts'), ['pdf_layouts' => ['invoice' => 'classic']])
            ->assertForbidden();
        $this->assertNull($this->org->fresh()->pdf_layouts);
    }

    public function test_a_layout_must_implement_a_document_interface(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PdfLayouts::class)->register(new class implements PdfLayoutInterface
        {
            public function key(): string
            {
                return 'x';
            }

            public function label(): string
            {
                return 'X';
            }
        });
    }
}
