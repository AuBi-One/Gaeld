<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Services\LedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\DebtRepayment;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Setting;

/**
 * Period-end grouping of a person's unpaid claims into a debt record of the
 * company towards that person, and repayment of that debt.
 */
final class Debts
{
    public function __construct(
        private LedgerService $ledger,
        private Accounts $accounts,
        private Claims $claims,
    ) {}

    /**
     * Group every approved, unpaid claim of the person dated on or before
     * $date into one debt record dated $date. The amounts move from the
     * claims' liability accounts to the debt account (no entry when they are
     * the same account).
     */
    public function convert(Person $person, string $date, ?string $notes = null): DebtRecord
    {
        return DB::transaction(function () use ($person, $date, $notes): DebtRecord {
            $orgId = (string) $person->organization_id;
            $claims = Claim::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('person_id', $person->id)
                ->where('status', Claim::STATUS_APPROVED)
                ->whereDate('date', '<=', $date)
                ->lockForUpdate()
                ->orderBy('date')
                ->get();

            if ($claims->isEmpty()) {
                throw new \DomainException(__('expense-claims::ec.nothing_to_convert'));
            }

            $settings = Setting::forOrganization($orgId);
            $debtAccount = $person->is_owner ? $settings->owner_debt_code : $settings->staff_debt_code;
            $total = Money::sumAmounts($claims->map(fn (Claim $c): array => ['amount' => (string) $c->total])->all());

            $lines = $claims
                ->filter(fn (Claim $c): bool => $c->liability_account_code !== $debtAccount)
                ->groupBy('liability_account_code')
                ->map(fn ($group, $code): JournalLineData => new JournalLineData(
                    accountId: $this->accounts->id($orgId, (string) $code),
                    debit: Money::sumAmounts($group->map(fn (Claim $c): array => ['amount' => (string) $c->total])->all()),
                    credit: '0',
                    // Line descriptions are limited to 255 characters; a year of claims exceeds it.
                    description: mb_strimwidth('Notes de frais '.$group->map->reference()->implode(', '), 0, 255, '…'),
                ))
                ->values()
                ->all();

            $entryId = null;
            if ($lines !== []) {
                $moved = Money::sumAmounts(array_map(fn (JournalLineData $l): array => ['amount' => $l->debit], $lines));
                $lines[] = new JournalLineData($this->accounts->id($orgId, $debtAccount), '0', $moved, "Dette envers {$person->name}");
                $entryId = $this->ledger->postEntry($orgId, new JournalEntryData(
                    date: $date,
                    reference: $this->accounts->uniqueReference($orgId, 'EC-DEBT-'.str_replace('-', '', $date).'-'.mb_strtoupper(mb_substr($person->name, 0, 3))),
                    description: "Frais non remboursés au {$date} — dette envers {$person->name}",
                    lines: $lines,
                ))->id;
            }

            $debt = DebtRecord::withoutGlobalScopes()->create([
                'organization_id' => $orgId,
                'person_id' => $person->id,
                'date' => $date,
                'amount' => $total,
                'account_code' => $debtAccount,
                'journal_entry_id' => $entryId,
                'notes' => $notes,
            ]);

            Claim::withoutGlobalScopes()->whereIn('id', $claims->pluck('id'))->update([
                'status' => Claim::STATUS_DEBT,
                'settled_via' => 'debt',
                'settled_on' => $date,
                'debt_record_id' => $debt->id,
            ]);

            return $debt;
        });
    }

    /** Undo a conversion that has no repayment yet. */
    public function cancel(DebtRecord $debt): void
    {
        DB::transaction(function () use ($debt): void {
            $debt = $this->locked($debt);
            if ($debt->repayments->isNotEmpty()) {
                throw new \DomainException(__('expense-claims::ec.debt_has_repayments'));
            }

            $this->claims->reverse($debt->journal_entry_id, 'Annulation dette du '.$debt->date->toDateString());
            Claim::withoutGlobalScopes()->where('debt_record_id', $debt->id)->update([
                'status' => Claim::STATUS_APPROVED,
                'settled_via' => null,
                'settled_on' => null,
                'debt_record_id' => null,
            ]);
            $debt->delete();
        });
    }

    public function repayByBank(DebtRecord $debt, string $date, string $amount): DebtRepayment
    {
        $amount = Money::normalize($amount);

        return DB::transaction(function () use ($debt, $date, $amount): DebtRepayment {
            $debt = $this->locked($debt)->load('person');
            if (! Money::isPositive($amount) || Money::compare($amount, $debt->remaining()) > 0) {
                throw new \DomainException(__('expense-claims::ec.repayment_too_high'));
            }
            $orgId = (string) $debt->organization_id;
            $entry = $this->ledger->postEntry($orgId, new JournalEntryData(
                date: $date,
                reference: $this->accounts->uniqueReference($orgId, 'EC-DEBT-PAY-'.str_replace('-', '', $date)),
                description: "Remboursement dette — {$debt->person->name}",
                lines: [
                    new JournalLineData($this->accounts->id($orgId, $debt->account_code), $amount, '0', "Dette du {$debt->date->toDateString()}"),
                    new JournalLineData($this->accounts->id($orgId, Setting::forOrganization($orgId)->bank_account_code), '0', $amount, $debt->person->name),
                ],
            ));

            return $debt->repayments()->create(['date' => $date, 'amount' => $amount, 'via' => 'bank', 'journal_entry_id' => $entry->id]);
        });
    }

    /** Reload the debt record with a row lock and its current repayments. */
    private function locked(DebtRecord $debt): DebtRecord
    {
        return DebtRecord::withoutGlobalScopes()->whereKey($debt->id)->lockForUpdate()->firstOrFail()->load('repayments');
    }
}
