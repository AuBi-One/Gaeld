<?php

namespace Plugins\ExpenseClaims\Models;

use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $debt_record_id
 * @property Carbon $date
 * @property string $amount
 * @property string $via
 * @property string|null $journal_entry_id
 * @property string|null $salary_slip_id
 */
class DebtRepayment extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $table = 'ec_debt_repayments';

    protected $fillable = ['organization_id', 'debt_record_id', 'date', 'amount', 'via', 'journal_entry_id', 'salary_slip_id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<DebtRecord, $this> */
    public function debtRecord(): BelongsTo
    {
        return $this->belongsTo(DebtRecord::class);
    }
}
