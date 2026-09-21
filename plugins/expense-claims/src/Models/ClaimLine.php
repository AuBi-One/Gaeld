<?php

namespace Plugins\ExpenseClaims\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $claim_id
 * @property int $position
 * @property string $type
 * @property string|null $description
 * @property string|null $from_place_id
 * @property string|null $to_place_id
 * @property bool|null $round_trip
 * @property string|null $km_lookup
 * @property string|null $km
 * @property string|null $km_source
 * @property string|null $km_override_reason
 * @property string|null $vehicle_type
 * @property string|null $rate
 * @property string $amount
 * @property string $expense_account_code
 * @property-read Place|null $fromPlace
 * @property-read Place|null $toPlace
 */
class ClaimLine extends Model
{
    use HasUuids;

    protected $table = 'ec_claim_lines';

    public const TYPES = ['km', 'meal', 'accommodation', 'transport', 'other'];

    protected $fillable = ['claim_id', 'position', 'type', 'description', 'from_place_id', 'to_place_id', 'round_trip', 'km_lookup', 'km', 'km_source', 'km_override_reason', 'vehicle_type', 'rate', 'amount', 'expense_account_code'];

    protected function casts(): array
    {
        return ['round_trip' => 'boolean'];
    }

    /** @return BelongsTo<Place, $this> */
    public function fromPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'from_place_id');
    }

    /** @return BelongsTo<Place, $this> */
    public function toPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'to_place_id');
    }
}
