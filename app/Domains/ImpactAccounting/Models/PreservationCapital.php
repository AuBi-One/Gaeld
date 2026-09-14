<?php

namespace App\Domains\ImpactAccounting\Models;

use App\Domains\Organizations\Models\Organization;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An organization-specific entity that the preservation workflow must protect.
 *
 * This model stores the definition and provenance of a capital, not a monetary
 * valuation. Observations and preservation actions will be separate records.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $description
 * @property string|null $state_translator
 * @property string|null $unit
 * @property string|null $threshold
 * @property string|null $threshold_direction
 * @property string|null $source_reference
 * @property string $status
 * @property Carbon|null $validated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PreservationCapital extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'state_translator',
        'unit',
        'threshold',
        'threshold_direction',
        'source_reference',
        'status',
        'validated_at',
    ];

    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<CapitalObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(CapitalObservation::class);
    }

    /** @return HasMany<PreservationAction, $this> */
    public function preservationActions(): HasMany
    {
        return $this->hasMany(PreservationAction::class);
    }

    /** @return HasMany<CapitalImpact, $this> */
    public function impacts(): HasMany
    {
        return $this->hasMany(CapitalImpact::class);
    }
}
