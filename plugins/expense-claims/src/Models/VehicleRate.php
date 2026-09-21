<?php

namespace Plugins\ExpenseClaims\Models;

use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $vehicle_type
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property string $rate_per_km
 * @property string|null $note
 */
class VehicleRate extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'ec_vehicle_rates';

    protected $fillable = ['organization_id', 'vehicle_type', 'valid_from', 'valid_to', 'rate_per_km', 'note'];

    protected function casts(): array
    {
        return ['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }
}
