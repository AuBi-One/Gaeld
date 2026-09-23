<?php

namespace Plugins\ExpenseClaims\Tests;

require_once __DIR__.'/ExpenseClaimsTestCase.php';

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Models\SalarySlip;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Services\Accounts;
use Plugins\ExpenseClaims\Services\Claims;
use Plugins\ExpenseClaims\Services\Debts;
use Plugins\ExpenseClaims\Services\Journal;

/**
 * Grouped accounting entries (docs/DESIGN-expense-claims.md §8.4).
 */
class GroupedEntriesTest extends ExpenseClaimsTestCase
{
    private Person $anna;

    private Person $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = Person::create(['organization_id' => $this->org->id, 'name' => 'Anna Muster', 'employee_id' => $this->employee->id]);
        $this->owner = Person::create(['organization_id' => $this->org->id, 'name' => 'Owner', 'is_owner' => true]);
    }

    private function draft(Person $person, string $date, string $amount): Claim
    {
        return app(Claims::class)->saveDraft($this->org->id, [
            'person_id' => $person->id, 'date' => $date, 'title' => 'Repas', 'lines' => [['type' => 'meal', 'amount' => $amount]],
        ]);
    }

    /** @return array<string, string> account code => balance (debit - credit), posted entries only */
    private function balances(): array
    {
        return TransactionLine::query()
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->where('accounts.organization_id', $this->org->id)
            ->where('journal_entries.is_posted', true)
            ->selectRaw('accounts.code, SUM(transaction_lines.debit) - SUM(transaction_lines.credit) AS balance')
            ->groupBy('accounts.code')
            ->pluck('balance', 'code')
            ->map(fn ($b) => number_format((float) $b, 2, '.', ''))
            ->all();
    }

    /** @return Collection<int, array{code: string, debit: string, credit: string}> */
    private function lines(string $entryId): Collection
    {
        return TransactionLine::query()->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->where('journal_entry_id', $entryId)->orderBy('accounts.code')
            ->get(['accounts.code', 'debit', 'credit'])
            ->map(fn ($l): array => ['code' => (string) $l->code, 'debit' => (string) $l->debit, 'credit' => (string) $l->credit]);
    }

    #[Test]
    public function approving_a_selection_books_nothing_and_records_the_approver(): void
    {
        $a = $this->draft($this->anna, '2026-03-10', '10.00');
        $b = $this->draft($this->owner, '2026-04-20', '20.00');

        app(Claims::class)->approve([$a, $b], $this->user->id);

        $this->assertSame(0, JournalEntry::count());
        $this->assertSame($this->user->id, $b->fresh()->approved_by);
        $this->assertNotNull($a->fresh()->approved_at);
        $this->assertNull($a->fresh()->liability_account_code);
    }

    #[Test]
    public function paying_a_batch_books_the_cost_in_one_entry_per_person_on_the_payment_date(): void
    {
        Account::create(['organization_id' => $this->org->id, 'code' => '1000', 'name' => 'Caisse', 'type' => AccountType::Asset->value]);
        $claims = [$this->draft($this->anna, '2025-03-10', '10.00'), $this->draft($this->owner, '2026-03-20', '20.00'), $this->draft($this->anna, '2026-04-01', '5.00')];
        app(Claims::class)->approve($claims);

        app(Claims::class)->pay($claims, '2026-04-30', '1000');

        $this->assertSame(2, JournalEntry::count());
        $anna = JournalEntry::where('reference', 'EC-PAY-20260430-ANN')->sole();
        $this->assertSame('2026-04-30', $anna->date->toDateString()); // the cost lands in the month of payment
        $this->assertEquals([
            ['code' => '1000', 'debit' => '0.00', 'credit' => '15.00'],
            ['code' => '6640', 'debit' => '15.00', 'credit' => '0.00'],
        ], $this->lines($anna->id)->all());
        $this->assertSame($anna->id, $claims[2]->fresh()->settlement_entry_id);

        // Cancelling reopens that person's payment only.
        $this->assertSame(2, app(Claims::class)->cancelPayment($claims[0]->fresh()));
        $this->assertSame(Claim::STATUS_APPROVED, $claims[2]->fresh()->status);
        $this->assertSame(Claim::STATUS_SETTLED, $claims[1]->fresh()->status);
        $balances = $this->balances();
        $this->assertSame('20.00', $balances['6640']);
        $this->assertSame('-20.00', $balances['1000']);
    }

    #[Test]
    public function passing_a_batch_to_debt_writes_one_entry_and_one_record_per_person(): void
    {
        $owner2 = Person::create(['organization_id' => $this->org->id, 'name' => 'Second owner', 'is_owner' => true]);
        $claims = [$this->draft($this->owner, '2025-05-10', '10.00'), $this->draft($owner2, '2025-06-10', '20.00'), $this->draft($this->anna, '2025-07-10', '5.00'), $this->draft($this->anna, '2025-08-10', '6.00')];
        app(Claims::class)->approve($claims);

        $records = app(Debts::class)->convert($claims, '2025-12-31');

        $this->assertCount(3, $records);
        $this->assertSame(3, JournalEntry::count());
        $this->assertSame(3, DebtRecord::query()->distinct()->count('journal_entry_id'));
        $anna = DebtRecord::where('person_id', $this->anna->id)->sole();
        $this->assertSame('11.00', (string) $anna->amount);
        $this->assertEquals([
            ['code' => '2210', 'debit' => '0.00', 'credit' => '11.00'], // staff debt stays on 2210 (D1)
            ['code' => '6640', 'debit' => '11.00', 'credit' => '0.00'],
        ], $this->lines((string) $anna->journal_entry_id)->all());
        $balances = $this->balances();
        $this->assertSame('41.00', $balances['6640']);
        $this->assertSame('-30.00', $balances['2560']);

        // Cancelling one owner's record undoes only their entry.
        app(Debts::class)->cancel(DebtRecord::where('person_id', $owner2->id)->sole());
        $balances = $this->balances();
        $this->assertSame('-10.00', $balances['2560']);
        $this->assertSame('21.00', $balances['6640']);
        $this->assertSame(Claim::STATUS_APPROVED, $claims[1]->fresh()->status);
        $this->assertSame(Claim::STATUS_DEBT, $claims[0]->fresh()->status);
    }

    #[Test]
    public function claims_dated_after_the_payment_or_debt_date_are_refused(): void
    {
        $claim = $this->draft($this->owner, '2026-01-10', '10.00');
        app(Claims::class)->approve($claim);

        try {
            app(Claims::class)->pay($claim, '2026-01-01');
            $this->fail('paid before its date');
        } catch (\DomainException) {
        }
        $this->expectException(\DomainException::class);
        app(Debts::class)->convert([$claim], '2025-12-31');
    }

    #[Test]
    public function the_salary_entry_books_the_cost_per_account_without_accrual(): void
    {
        $claims = [$this->draft($this->anna, '2026-03-10', '10.00'), $this->draft($this->anna, '2026-03-12', '15.00')];
        app(Claims::class)->approve($claims);

        /** @var SalarySlip $slip */
        $slip = app(GeneratePayrollRunAction::class)->execute($this->org->id, 3, 2026, true, [], [[
            'employee_id' => $this->employee->id,
            'reimbursement_item_ids' => array_map(fn (Claim $c): string => 'claim:'.$c->id, $claims),
        ]])->first();

        $lines = $this->lines((string) $slip->fresh()->journal_entry_id);
        $this->assertEquals([['code' => '6640', 'debit' => '25.00', 'credit' => '0.00']], $lines->where('code', '6640')->values()->all());
        $this->assertCount(0, $lines->where('code', '2210'));
        $this->assertSame('25.00', $this->balances()['6640']); // counted once, in the salary month
    }

    #[Test]
    public function the_salary_cannot_pay_a_claim_dated_after_its_month(): void
    {
        $claim = $this->draft($this->anna, '2026-04-02', '10.00');
        app(Claims::class)->approve($claim);

        $this->expectException(\DomainException::class);
        app(GeneratePayrollRunAction::class)->execute($this->org->id, 3, 2026, true, [], [[
            'employee_id' => $this->employee->id,
            'reimbursement_item_ids' => ['claim:'.$claim->id],
        ]]);
    }

    #[Test]
    public function a_claim_booked_before_the_change_is_paid_from_its_liability(): void
    {
        // Approved before D37 (or migrated without --book): its cost is already on 2210.
        $claim = $this->draft($this->anna, '2026-03-10', '10.00');
        app(Claims::class)->approve($claim);
        $claim->forceFill(['liability_account_code' => '2210'])->save();

        try {
            app(Claims::class)->unapprove($claim->fresh());
            $this->fail('a claim booked before Gäld went back to draft');
        } catch (\DomainException) {
        }

        app(Claims::class)->pay($claim->fresh(), '2026-03-31');
        $this->assertEquals([
            ['code' => '1020', 'debit' => '0.00', 'credit' => '10.00'],
            ['code' => '2210', 'debit' => '10.00', 'credit' => '0.00'],
        ], $this->lines((string) $claim->fresh()->settlement_entry_id)->all());
    }

    #[Test]
    public function a_legacy_approval_entry_is_undone_when_the_approval_is_cancelled(): void
    {
        $claim = $this->draft($this->anna, '2026-03-10', '10.00');
        app(Claims::class)->approve($claim);
        $entry = app(Journal::class)->book($this->org->id, new JournalEntryData('2026-03-10', 'EC-0001', 'Legacy approval', [
            new JournalLineData(app(Accounts::class)->id($this->org->id, '6640'), '10.00', '0'),
            new JournalLineData(app(Accounts::class)->id($this->org->id, '2210'), '0', '10.00'),
        ]));
        $claim->forceFill(['liability_account_code' => '2210', 'journal_entry_id' => $entry->id])->save();

        app(Claims::class)->unapprove($claim->fresh());

        $this->assertSame(Claim::STATUS_DRAFT, $claim->fresh()->status);
        $this->assertSame([], array_filter($this->balances(), fn (string $b): bool => $b !== '0.00'));
    }

    #[Test]
    public function a_draft_debt_entry_is_deleted_when_its_record_is_cancelled(): void
    {
        $claims = [$this->draft($this->owner, '2025-05-10', '10.00'), $this->draft($this->anna, '2025-06-10', '20.00')];
        app(Claims::class)->approve($claims);
        app(Debts::class)->convert($claims, '2025-12-31', null, true);
        $this->assertSame(2, JournalEntry::where('is_posted', false)->count());

        app(Debts::class)->cancel(DebtRecord::where('person_id', $this->owner->id)->sole());

        $this->assertSame(1, JournalEntry::count()); // the owner's draft is gone, Anna's stays
        $this->assertSame(JournalEntry::sole()->id, DebtRecord::sole()->journal_entry_id);
    }
}
