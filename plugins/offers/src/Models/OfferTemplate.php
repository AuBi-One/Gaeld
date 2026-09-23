<?php

namespace Plugins\Offers\Models;

use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Starting point for new offers: subject, texts, validity and default lines.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $title
 * @property string|null $intro
 * @property string|null $closing
 * @property int $validity_days
 * @property list<array{type: string, label: string|null, description: string, quantity: string|null, unit: string|null, unit_price: string|null}>|null $lines
 * @property bool $is_default
 */
class OfferTemplate extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'of_templates';

    protected $fillable = ['organization_id', 'name', 'title', 'intro', 'closing', 'validity_days', 'lines', 'is_default'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'is_default' => 'boolean', 'validity_days' => 'integer'];
    }
}
