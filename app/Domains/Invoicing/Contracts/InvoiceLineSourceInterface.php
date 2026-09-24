<?php

namespace App\Domains\Invoicing\Contracts;

use App\Domains\Invoicing\DTOs\InvoiceLineSourceReference;

/**
 * A kind of record invoice lines can be taken from (e.g. positions of an
 * offer). Registered in InvoiceLineSources; the invoice form then offers an
 * "add line from …" action, the line keeps `source_type` + `source_id`, and
 * the invoice page links back to the record.
 */
interface InvoiceLineSourceInterface
{
    /** Stored in invoice_lines.source_type (short, stable, e.g. `offer_line`). */
    public function type(): string;

    /** Label of the action on the invoice form (translated), e.g. "Add line from offer". */
    public function label(): string;

    /**
     * URL of a JSON endpoint called with `?customer_id=` that returns
     * `{title, empty, hide_complete_label?, groups: [{label, options: [{source_id, label, reference,
     * complete?: bool, introduction?: string, line: {type, description, quantity, unit_price, vat_rate_id}}]}]}`
     * (`introduction` prefills the invoice's introduction when it is still empty).
     * With `hide_complete_label`, the picker offers a checkbox (remembered per browser)
     * that hides the options marked `complete` (e.g. positions already invoiced in full).
     */
    public function pickerUrl(): string;

    /**
     * The records behind these source ids that belong to the organisation;
     * ids that do not exist there are left out (used to validate and to link).
     *
     * @param  list<string>  $sourceIds
     * @return array<array-key, InvoiceLineSourceReference> source id => reference (numeric ids become int keys)
     */
    public function describe(string $organizationId, array $sourceIds): array;
}
