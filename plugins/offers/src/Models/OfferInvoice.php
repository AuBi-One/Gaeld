<?php

namespace Plugins\Offers\Models;

use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice created from an offer, with the amount invoiced per offer line.
 *
 * @property int $id
 * @property string $offer_id
 * @property string $invoice_id
 * @property-read Offer $offer
 * @property-read Invoice|null $invoice
 * @property-read Collection<int, OfferInvoiceLine> $lines
 */
class OfferInvoice extends Model
{
    protected $table = 'of_offer_invoices';

    protected $fillable = ['offer_id', 'invoice_id'];

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasMany<OfferInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OfferInvoiceLine::class);
    }
}
