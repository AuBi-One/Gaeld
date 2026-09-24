<?php

namespace Plugins\ExpenseClaims\Models;

use App\Support\Money;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $person_id
 * @property Carbon $date
 * @property string $amount
 * @property string $account_code
 * @property string|null $journal_entry_id
 * @property string|null $notes
 * @property-read Person|null $person
 * @property-read Collection<int, DebtRepayment> $repayments
 */
class DebtRecord extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $table = 'ec_debt_records';

    protected $fillable = ['organization_id', 'person_id', 'date', 'amount', 'account_code', 'journal_entry_id', 'notes'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return HasMany<DebtRepayment, $this> */
    public function repayments(): HasMany
    {
        return $this->hasMany(DebtRepayment::class);
    }

    public function remaining(): string
    {
        return Money::subtract((string) $this->amount, Money::sumAmounts($this->repayments->map(fn (DebtRepayment $r): array => ['amount' => (string) $r->amount])->all()));
    }
}
