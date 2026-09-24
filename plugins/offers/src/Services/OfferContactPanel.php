<?php

namespace Plugins\Offers\Services;

use App\Domains\Contacts\DTOs\ContactPanel;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Enums\Permission;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Support\OfferInvoicing;

/**
 * "Offers" section on the contact page: the contact's latest offers with what
 * remains to invoice (accepted offers), for users who may see offers.
 */
class OfferContactPanel
{
    private const LIMIT = 20;

    public function __invoke(Contact $contact): ?ContactPanel
    {
        if (! request()->user()?->hasPermissionTo(Permission::InvoicingView)) {
            return null;
        }

        $offers = Offer::query()->where('contact_id', $contact->id)
            ->orderByDesc('offer_date')->orderByDesc('number')
            ->limit(self::LIMIT)
            ->get(['id', 'number', 'status', 'offer_date', 'valid_until', 'total', 'currency']);
        $invoicing = OfferInvoicing::perOffer(Offer::query()->whereKey($offers->pluck('id')->all()));

        $rows = array_values($offers->map(function (Offer $offer) use ($invoicing): array {
            $row = $invoicing->get($offer->id);
            // What remains to invoice, for accepted offers only (others: empty cell).
            $remaining = $offer->status === Offer::STATUS_ACCEPTED && $row !== null
                ? number_format((float) $row->remaining, 2, '.', '')
                : null;

            return [
                'cells' => [
                    'number' => $offer->number,
                    'date' => $offer->offer_date->toDateString(),
                    'status' => (string) __('offers::of.status_'.($offer->isExpired() ? 'expired' : $offer->status)),
                    'total' => (string) $offer->total,
                    'remaining' => $remaining,
                ],
                'href' => "/offers/{$offer->id}",
                'currency' => $offer->currency,
            ];
        })->all());

        return new ContactPanel(
            title: (string) __('offers::of.title_offers'),
            columns: [
                ['key' => 'number', 'label' => (string) __('offers::of.number')],
                ['key' => 'date', 'label' => (string) __('offers::of.offer_date'), 'type' => 'date'],
                ['key' => 'status', 'label' => (string) __('app.status')],
                ['key' => 'total', 'label' => (string) __('offers::of.total'), 'align' => 'right', 'type' => 'money'],
                ['key' => 'remaining', 'label' => (string) __('offers::of.remaining_to_invoice'), 'align' => 'right', 'type' => 'money'],
            ],
            rows: $rows,
            emptyText: (string) __('offers::of.no_offers'),
            action: ['label' => (string) __('offers::of.all_offers_of_contact'), 'href' => "/offers?contact={$contact->id}"],
        );
    }
}
