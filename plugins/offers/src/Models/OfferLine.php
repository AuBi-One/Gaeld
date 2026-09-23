<?php

namespace Plugins\Offers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $offer_id
 * @property int $sort
 * @property string $type
 * @property string|null $label
 * @property string $description
 * @property string $quantity
 * @property string|null $unit
 * @property string $unit_price
 * @property string $amount
 * @property string $vat_amount
 */
class OfferLine extends Model
{
    protected $table = 'of_offer_lines';

    public const TYPE_ITEM = 'item';

    public const TYPE_TEXT = 'text';

    protected $fillable = ['offer_id', 'sort', 'type', 'label', 'description', 'quantity', 'unit', 'unit_price', 'amount', 'vat_amount'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function isItem(): bool
    {
        return $this->type === self::TYPE_ITEM;
    }
}
