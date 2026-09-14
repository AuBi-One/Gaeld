<?php

namespace App\Domains\ImpactAccounting\Models;

use App\Domains\ImpactAccounting\Enums\PreservationActionType;
use App\Domains\Organizations\Models\Organization;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A prevention, restoration, or avoidance action in a capital preservation plan.
 *
 * Avoidance remains distinct from preservation actions so reporting can retain
 * the CARE distinction between changing exploitation and repairing degradation.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $preservation_capital_id
 * @property string $title
 * @property string|null $description
 * @property PreservationActionType $action_type
 * @property string $status
 * @property Carbon|null $due_on
 * @property string|null $source_reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PreservationAction extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'preservation_capital_id',
        'title',
        'description',
        'action_type',
        'status',
        'due_on',
        'source_reference',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'action_type' => PreservationActionType::class,
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
