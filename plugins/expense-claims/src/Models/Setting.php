<?php

namespace Plugins\ExpenseClaims\Models;

use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $expense_account_code
 * @property string $staff_liability_code
 * @property string $owner_liability_code
 * @property string $staff_debt_code
 * @property string $owner_debt_code
 * @property string $bank_account_code
 */
class Setting extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'ec_settings';

    protected $fillable = ['organization_id', 'expense_account_code', 'staff_liability_code', 'owner_liability_code', 'staff_debt_code', 'owner_debt_code', 'bank_account_code'];

    /**
     * Defaults follow the Swiss SME chart (Banana, "Plan comptable PME"):
     * 6640 travel expenses, 2210 other debts (staff), 2260 short-term debts
     * towards related persons (owners and organs, CO 959a), also for their
     * debt records (D47: repayable on demand, i.e. within 12 months; use 2560
     * when repayment is deferred beyond 12 months by agreement), 1020 bank.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'expense_account_code' => '6640',
        'staff_liability_code' => '2210',
        'owner_liability_code' => '2260',
        'staff_debt_code' => '2210',
        'owner_debt_code' => '2260',
        'bank_account_code' => '1020',
    ];

    public static function forOrganization(string $organizationId): self
    {
        return self::withoutGlobalScopes()->firstOrCreate(
            ['organization_id' => $organizationId],
            self::DEFAULTS,
        );
    }
}
