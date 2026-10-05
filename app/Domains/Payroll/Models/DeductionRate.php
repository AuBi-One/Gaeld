<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Models\Organization;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line (e.g. AVS employee, AVS employer) of a deduction rate set.
 *
 * @property int $id
 * @property string $organization_id
 * @property int|null $deduction_rate_set_id
 * @property string $name
 * @property string $code
 * @property string $rate
 * @property string $type
 * @property int|null $account_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DeductionRate extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'deduction_rate_set_id',
        'name',
        'code',
        'rate',
        'type',
        'account_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<DeductionRateSet, $this> */
    public function set(): BelongsTo
    {
        return $this->belongsTo(DeductionRateSet::class, 'deduction_rate_set_id');
    }

    /**
     * The chart-of-accounts entry this deduction is posted to (e.g. the AVS
     * payable liability account), so payroll posting can be mapped per rate
     * instead of to a fixed set of accounts.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
