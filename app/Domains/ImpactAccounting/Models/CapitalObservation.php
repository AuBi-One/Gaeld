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
 * A dated observation of a capital's state translator.
 *
 * Values remain unopinionated and provenance is mandatory at the data-model
 * level so that the workflow can represent numeric and qualitative evidence.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $preservation_capital_id
 * @property Carbon $observed_on
 * @property string $value
 * @property string|null $unit
 * @property string $source_reference
 * @property string|null $confidence
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CapitalObservation extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'preservation_capital_id',
        'observed_on',
        'value',
        'unit',
        'source_reference',
        'confidence',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'observed_on' => 'date',
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
