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
 * A phase-one description of an organization's activity and operating model.
 *
 * This is workshop evidence, not a financial transaction or an impact score.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $purpose
 * @property string|null $inputs
 * @property string|null $outputs
 * @property string $source_reference
 * @property string $status
 * @property Carbon|null $validated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrganizationActivity extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'purpose',
        'inputs',
        'outputs',
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
}
