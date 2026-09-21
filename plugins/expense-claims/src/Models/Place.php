<?php

namespace Plugins\ExpenseClaims\Models;

use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $kind
 * @property string $label
 * @property string|null $address
 * @property string|null $postal_code
 * @property string|null $city
 * @property string $country
 * @property string|null $lat
 * @property string|null $lon
 * @property int|null $contact_id
 */
class Place extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'ec_places';

    public const KINDS = ['hq', 'home', 'client', 'other'];

    protected $fillable = ['organization_id', 'kind', 'label', 'address', 'postal_code', 'city', 'country', 'lat', 'lon', 'contact_id'];

    /**
     * Office first, then homes, clients and others; by label within a kind.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw("CASE kind WHEN 'hq' THEN 0 WHEN 'home' THEN 1 WHEN 'client' THEN 2 ELSE 3 END")->orderBy('label');
    }

    public function hasCoordinates(): bool
    {
        return $this->lat !== null && $this->lon !== null;
    }

    public function addressLine(): string
    {
        return trim(implode(', ', array_filter([$this->address, trim(($this->postal_code ?? '').' '.($this->city ?? ''))])));
    }
}
