<?php

namespace Plugins\ExpenseClaims\Models;

use App\Domains\Users\Models\User;
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
 * @property int $number
 * @property string $person_id
 * @property Carbon $date
 * @property string $title
 * @property string|null $notes
 * @property string $status
 * @property string $total
 * @property string|null $liability_account_code
 * @property string|null $journal_entry_id
 * @property string|null $settled_via
 * @property Carbon|null $settled_on
 * @property string|null $settlement_entry_id
 * @property string|null $salary_slip_id
 * @property string|null $debt_record_id
 * @property list<array{path: string, name: string}>|null $attachments
 * @property string $source
 * @property string|null $external_ref
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property-read User|null $approver
 * @property-read DebtRecord|null $debtRecord
 * @property-read Person|null $person
 * @property-read Collection<int, ClaimLine> $lines
 */
class Claim extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'ec_claims';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    /** Paid: with a salary, from a bank/cash account, or before Gäld (settled_via). */
    public const STATUS_SETTLED = 'settled';

    /** Moved into a debt record (not paid; the debt record tracks its repayment). */
    public const STATUS_DEBT = 'debt';

    protected $fillable = ['organization_id', 'number', 'person_id', 'date', 'title', 'notes', 'status', 'total', 'liability_account_code', 'journal_entry_id', 'settled_via', 'settled_on', 'settlement_entry_id', 'salary_slip_id', 'debt_record_id', 'attachments', 'source', 'external_ref', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'settled_on' => 'date:Y-m-d', 'attachments' => 'array', 'approved_at' => 'datetime'];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<DebtRecord, $this> */
    public function debtRecord(): BelongsTo
    {
        return $this->belongsTo(DebtRecord::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<ClaimLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ClaimLine::class)->orderBy('position');
    }

    public function reference(): string
    {
        return 'EC-'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
