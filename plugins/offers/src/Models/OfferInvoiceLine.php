<?php

namespace Plugins\Offers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Net amount of one offer line put on an invoice, as created from the offer.
 *
 * @property int $id
 * @property int $offer_invoice_id
 * @property int|null $offer_line_id null once the offer line was replaced (reopened draft)
 * @property string $amount
 * @property-read OfferInvoice $link
 */
class OfferInvoiceLine extends Model
{
    protected $table = 'of_offer_invoice_lines';

    protected $fillable = ['offer_invoice_id', 'offer_line_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<OfferInvoice, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(OfferInvoice::class, 'offer_invoice_id');
    }
}
