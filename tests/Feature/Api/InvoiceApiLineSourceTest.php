<?php

namespace Tests\Feature\Api;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;

/**
 * A sparse API update (no `lines`) rebuilds the lines from the stored ones:
 * their source reference (InvoiceLineSources) must be kept.
 */
class InvoiceApiLineSourceTest extends SecurityTestCase
{
    public function test_a_sparse_update_keeps_the_line_source(): void
    {
        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);

        $customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Client AG']);
        $invoice = Invoice::factory()->create(['organization_id' => $this->orgA->id, 'customer_id' => $customer->id]);
        $invoice->lines()->create([
            'description' => 'Taken from a record',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'amount' => '100.00',
            'source_type' => 'demo_item',
            'source_id' => '1',
        ]);

        $token = $this->createApiToken($this->ownerA, $this->orgA);
        $this->withToken($token)->putJson("/api/v1/invoices/{$invoice->id}", [
            'due_date' => now()->addDays(45)->toDateString(),
        ])->assertOk();

        $line = $invoice->fresh()->lines()->sole();
        $this->assertSame(['demo_item', '1'], [$line->source_type, $line->source_id]);
    }
}
