<?php

namespace Plugins\Offers\Services;

use App\Domains\Invoicing\Contracts\InvoiceLineSourceInterface;
use App\Domains\Invoicing\DTOs\InvoiceLineSourceReference;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Models\OfferLine;
use Plugins\Offers\Support\OfferInvoicing;

/**
 * Positions of offers as a source of invoice lines: "Add line from offer" on
 * the core invoice form, and the link back from the invoice to the offer.
 */
class OfferLineSource implements InvoiceLineSourceInterface
{
    public function type(): string
    {
        return OfferInvoicing::SOURCE;
    }

    public function label(): string
    {
        return (string) __('offers::of.add_line_from_offer');
    }

    public function pickerUrl(): string
    {
        return '/offers/line-source';
    }

    public function describe(string $organizationId, array $sourceIds): array
    {
        $ids = array_values(array_filter($sourceIds, fn (string $id): bool => ctype_digit($id)));
        if ($ids === []) {
            return [];
        }

        $references = [];
        $lines = OfferLine::query()
            ->join('of_offers', 'of_offers.id', '=', 'of_offer_lines.offer_id')
            ->where('of_offers.organization_id', $organizationId)
            // Only positions of accepted offers can be invoiced (their lines no longer change).
            ->where('of_offers.status', Offer::STATUS_ACCEPTED)
            ->where('of_offer_lines.type', OfferLine::TYPE_ITEM)
            ->whereIn('of_offer_lines.id', array_map('intval', $ids))
            ->get(['of_offer_lines.id', 'of_offer_lines.label', 'of_offer_lines.sort', 'of_offers.id as offer_uuid', 'of_offers.number']);
        foreach ($lines as $line) {
            $references[(string) $line->id] = new InvoiceLineSourceReference(
                self::reference((string) $line->getAttribute('number'), $line),
                '/offers/'.$line->getAttribute('offer_uuid'),
            );
        }

        return $references;
    }

    /** "Offer OF-2026-004 · pos. 1" */
    public static function reference(string $number, OfferLine $line): string
    {
        return (string) __('offers::of.source_reference', ['number' => $number, 'pos' => $line->label ?: (string) ($line->sort + 1)]);
    }
}
