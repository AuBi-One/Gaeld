<?php

namespace Plugins\Offers\Support;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\Offers\Models\Offer;

/**
 * What was invoiced from offers, read from the core invoice lines that carry
 * `source_type = offer_line` (set by "Create invoice" on an offer and by "Add
 * line from offer" on the invoice form). Edits and deletions of such lines in
 * core are therefore always reflected. Only invoices that count: not deleted,
 * not cancelled, not credit notes, of the offer's own organisation.
 */
final class OfferInvoicing
{
    public const SOURCE = 'offer_line';

    /** Invoice lines taken from offer lines (aliases: il = invoice line, i = invoice, l = offer line). */
    public static function lines(bool $withCancelled = false): Builder
    {
        return DB::table('invoice_lines as il')
            ->join('invoices as i', 'i.id', '=', 'il.invoice_id')
            ->join('of_offer_lines as l', DB::raw('CAST(l.id AS TEXT)'), '=', 'il.source_id')
            ->join('of_offers as o', 'o.id', '=', 'l.offer_id')
            ->whereColumn('o.organization_id', 'i.organization_id')
            ->whereColumn('o.contact_id', 'i.customer_id') // an invoice to another client does not count
            ->where('il.source_type', self::SOURCE)
            ->where('il.type', 'item')
            ->whereNull('i.deleted_at')
            ->where('i.type', InvoiceType::Invoice->value)
            ->when(! $withCancelled, fn (Builder $q) => $q->where('i.status', '!=', InvoiceStatus::Cancelled->value));
    }

    /** @return array<int, string> offer line id => net amount invoiced */
    public static function invoicedByLine(Offer $offer): array
    {
        return self::lines()->where('l.offer_id', $offer->id)
            ->groupBy('l.id')
            ->selectRaw('l.id, SUM(il.amount) AS invoiced')
            ->pluck('invoiced', 'id')
            ->map(fn ($v): string => number_format((float) $v, 2, '.', ''))
            ->all();
    }

    public static function hasInvoice(Offer $offer): bool
    {
        return self::lines()->where('l.offer_id', $offer->id)->exists();
    }

    /**
     * The invoices with lines from this offer (cancelled ones too, for the history).
     *
     * @return Collection<int, \stdClass> id, number, status, issue_date, total, net_from_offer
     */
    public static function invoices(Offer $offer): Collection
    {
        return self::lines(withCancelled: true)->where('l.offer_id', $offer->id)
            ->groupBy('i.id', 'i.number', 'i.status', 'i.issue_date', 'i.total')
            ->selectRaw('i.id, i.number, i.status, i.issue_date, i.total, SUM(il.amount) AS net_from_offer')
            ->orderBy('i.issue_date')->orderBy('i.number')
            ->get();
    }
}
