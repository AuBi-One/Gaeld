<?php

namespace Tests\Feature\Api;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;

/** The invoice introduction through the API: returned, updated, kept by a sparse update. */
class InvoiceApiIntroductionTest extends SecurityTestCase
{
    public function test_the_introduction_is_returned_updated_and_kept_by_a_sparse_update(): void
    {
        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);

        $customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Client AG']);
        $invoice = Invoice::factory()->create(['organization_id' => $this->orgA->id, 'customer_id' => $customer->id, 'introduction' => 'Before']);
        $token = $this->createApiToken($this->ownerA, $this->orgA);

        $this->withToken($token)->getJson("/api/v1/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.introduction', 'Before');

        // Each update carries its own idempotency key (the API refuses a repeated request without one).
        $this->withToken($token)->withHeader('Idempotency-Key', 'intro-1')->putJson("/api/v1/invoices/{$invoice->id}", ['introduction' => 'Mandat de conseil'])->assertOk();
        $this->assertSame('Mandat de conseil', $invoice->fresh()->introduction);

        $this->withToken($token)->withHeader('Idempotency-Key', 'intro-2')->putJson("/api/v1/invoices/{$invoice->id}", ['due_date' => now()->addDays(45)->toDateString()])->assertOk();
        $this->assertSame('Mandat de conseil', $invoice->fresh()->introduction);

        $this->withToken($token)->withHeader('Idempotency-Key', 'intro-3')->putJson("/api/v1/invoices/{$invoice->id}", ['introduction' => null])->assertOk();
        $this->assertNull($invoice->fresh()->introduction);
    }
}
