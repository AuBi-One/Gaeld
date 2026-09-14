<?php

namespace App\Domains\ImpactAccounting\Models;

use App\Domains\Organizations\Models\Organization;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records how an organization activity affects a preservation capital.
 *
 * The activity is deliberately stored as descriptive context rather than a
 * foreign key to one domain: invoicing, expenses, payroll, and assets can all
 * produce impacts without changing their legal accounting records.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $preservation_capital_id
 * @property string $activity_name
 * @property string $impact_type
 * @property string $impact_direction
 * @property Carbon $occurred_on
 * @property string|null $value
 * @property string|null $unit
 * @property string $source_reference
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CapitalImpact extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'preservation_capital_id',
        'activity_name',
        'impact_type',
        'impact_direction',
        'occurred_on',
        'value',
        'unit',
        'source_reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<PreservationCapital, $this> */
    public function preservationCapital(): BelongsTo
    {
        return $this->belongsTo(PreservationCapital::class);
    }
}
