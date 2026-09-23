<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CreateCreditNoteAction;
use App\Domains\Invoicing\Actions\DuplicateInvoiceAction;
use App\Domains\Invoicing\Contracts\InvoiceLineSourceInterface;
use App\Domains\Invoicing\DTOs\InvoiceLineSourceReference;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceLineSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Invoice lines taken from a record of a registered source keep their
 * reference through saves, are validated against the organisation, and
 * link back to the record on the invoice page.
 */
class InvoiceLineSourceTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->customer = Contact::create(['organization_id' => $this->organization->id, 'name' => 'Source Client AG']);

        $orgId = $this->organization->id;
        app(InvoiceLineSources::class)->register(new class($orgId) implements InvoiceLineSourceInterface
        {
            public function __construct(private string $orgId) {}

            public function type(): string
            {
                return 'demo_item';
            }

            public function label(): string
            {
                return 'Add line from demo';
            }

            public function pickerUrl(): string
            {
                return '/demo/line-source';
            }

            public function describe(string $organizationId, array $sourceIds): array
            {
                // Records "1" and "2" exist in the test organisation only.
                $known = $organizationId === $this->orgId ? ['1', '2'] : [];

                return collect($sourceIds)->intersect($known)
                    ->mapWithKeys(fn (string $id): array => [$id => new InvoiceLineSourceReference("Demo {$id}", "/demo/{$id}")])
                    ->all();
            }
        });
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function payload(array $lines): array
    {
        return [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-03-10',
            'due_date' => '2026-03-31',
            'currency' => 'CHF',
            'lines' => $lines,
        ];
    }

    private function line(?string $type = 'demo_item', ?string $id = '1', string $description = 'From demo'): array
    {
        return ['description' => $description, 'quantity' => 1, 'unit_price' => 100, 'source_type' => $type, 'source_id' => $id];
    }

    public function test_the_source_is_kept_through_create_and_update_and_links_back(): void
    {
        $create = $this->actAsOrg()->post('/invoices', $this->payload([$this->line(), ['description' => 'Plain', 'quantity' => 1, 'unit_price' => 10]]));
        $invoice = Invoice::findOrFail(basename((string) $create->headers->get('Location')));
        $lines = $invoice->lines()->orderBy('sort_order')->get();
        $this->assertSame(['demo_item', '1'], [$lines[0]->source_type, $lines[0]->source_id]);
        $this->assertNull($lines[1]->source_type);

        // update replaces the lines: the reference comes back with the payload
        $this->actAsOrg()->put("/invoices/{$invoice->id}", ['number' => $invoice->number] + $this->payload([
            $this->line(description: 'From demo - 50%'), $this->line(id: '2'),
        ]))->assertSessionHasNoErrors();
        $this->assertSame(['1', '2'], $invoice->lines()->orderBy('sort_order')->pluck('source_id')->all());

        $line = $invoice->lines()->where('source_id', '1')->firstOrFail();
        $this->actAsOrg()->get("/invoices/{$invoice->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where("lineSourceRefs.{$line->id}.label", 'Demo 1')->where("lineSourceRefs.{$line->id}.url", '/demo/1'));
        $this->actAsOrg()->get("/invoices/{$invoice->id}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->where('lineSources.0.type', 'demo_item')->where('lineSources.0.label', 'Add line from demo')
                ->where("lineSourceRefs.{$line->id}.label", 'Demo 1'));
        $this->actAsOrg()->get('/invoices/create')->assertOk()->assertInertia(fn ($page) => $page->where('lineSources.0.picker_url', '/demo/line-source'));
    }

    public function test_unknown_types_and_records_of_other_organisations_are_refused(): void
    {
        $this->actAsOrg()->post('/invoices', $this->payload([$this->line(type: 'nope')]))->assertSessionHasErrors('lines.0.source_id');
        $this->actAsOrg()->post('/invoices', $this->payload([$this->line(id: '99')]))->assertSessionHasErrors('lines.0.source_id');
        $this->actAsOrg()->post('/invoices', $this->payload([$this->line(id: null)]))->assertSessionHasErrors('lines.0.source_id');
        $this->actAsOrg()->post('/invoices', $this->payload([$this->line(type: null)]))->assertSessionHasErrors('lines.0.source_type');
        $this->assertSame(0, Invoice::query()->count());

        // empty strings (multipart forms) mean no source
        $this->actAsOrg()->post('/invoices', $this->payload([$this->line(type: '', id: '')]))->assertSessionHasNoErrors();
        $this->assertNull(Invoice::query()->sole()->lines()->sole()->source_type);
    }

    public function test_a_duplicate_or_credit_note_does_not_copy_the_source(): void
    {
        $create = $this->actAsOrg()->post('/invoices', $this->payload([$this->line()]));
        $invoice = Invoice::findOrFail(basename((string) $create->headers->get('Location')));

        $copy = app(DuplicateInvoiceAction::class)->execute($invoice);
        $this->assertNull($copy->lines()->sole()->source_id);

        $invoice->update(['status' => InvoiceStatus::Sent]);
        $creditNote = app(CreateCreditNoteAction::class)->execute($invoice->fresh());
        $this->assertNull($creditNote->lines()->sole()->source_id);
    }

    public function test_only_amount_lines_take_a_source(): void
    {
        $text = ['type' => 'text', 'description' => 'Note', 'source_type' => 'demo_item', 'source_id' => '1'];
        $this->actAsOrg()->post('/invoices', $this->payload([$text]))->assertSessionHasErrors('lines.0.source_id');
    }

    public function test_a_reference_the_invoice_already_has_is_kept_even_if_its_record_is_gone(): void
    {
        $create = $this->actAsOrg()->post('/invoices', $this->payload([$this->line()]));
        $invoice = Invoice::findOrFail(basename((string) $create->headers->get('Location')));
        $invoice->lines()->update(['source_id' => '7']); // record 7 no longer known to the source

        $this->actAsOrg()->put("/invoices/{$invoice->id}", ['number' => $invoice->number] + $this->payload([$this->line(id: '7', description: 'Edited')]))
            ->assertSessionHasNoErrors();
        $this->assertSame('7', $invoice->lines()->sole()->source_id);

        // a new unknown reference is still refused
        $this->actAsOrg()->put("/invoices/{$invoice->id}", ['number' => $invoice->number] + $this->payload([$this->line(id: '8')]))
            ->assertSessionHasErrors('lines.0.source_id');
    }
}
