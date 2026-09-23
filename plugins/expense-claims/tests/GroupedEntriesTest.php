<?php

namespace Plugins\ExpenseClaims\Tests;

require_once __DIR__.'/ExpenseClaimsTestCase.php';

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
use Plugins\ExpenseClaims\Services\Claims;
use Plugins\ExpenseClaims\Services\Debts;

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
    public function approving_a_selection_writes_one_entry_per_month_with_a_line_per_person(): void
    {
        $a = $this->draft($this->anna, '2026-03-10', '10.00');
        $b = $this->draft($this->owner, '2026-03-20', '20.00');
        $c = $this->draft($this->anna, '2026-03-25', '5.00');
        $d = $this->draft($this->anna, '2026-04-02', '7.00');

        app(Claims::class)->approve([$a, $b, $c, $d], $this->user->id);

        $this->assertSame(2, JournalEntry::count());
        $march = JournalEntry::where('reference', 'EC-APP-202603')->sole();
        $this->assertSame('2026-03-25', $march->date->toDateString()); // latest claim of the month
        $this->assertEquals([
            ['code' => '2210', 'debit' => '0.00', 'credit' => '15.00'], // Anna, two claims, one line
            ['code' => '2260', 'debit' => '0.00', 'credit' => '20.00'],
            ['code' => '6640', 'debit' => '35.00', 'credit' => '0.00'],
        ], $this->lines($march->id)->all());
        $this->assertSame('EC-0004', JournalEntry::whereDate('date', '2026-04-02')->value('reference')); // a single claim keeps its reference
        $this->assertSame($march->id, $b->fresh()->journal_entry_id);
        $this->assertSame($this->user->id, $b->fresh()->approved_by);
    }

    #[Test]
    public function cancelling_one_approval_of_a_group_counter_books_only_that_claim(): void
    {
        $a = $this->draft($this->anna, '2026-03-10', '10.00');
        $b = $this->draft($this->owner, '2026-03-20', '20.00');
        app(Claims::class)->approve([$a, $b]);

        app(Claims::class)->unapprove($b->fresh());

        $this->assertSame(Claim::STATUS_DRAFT, $b->fresh()->status);
        $this->assertNull($b->fresh()->approved_at);
        $this->assertSame(Claim::STATUS_APPROVED, $a->fresh()->status);
        $balances = $this->balances();
        $this->assertSame('10.00', $balances['6640']);
        $this->assertSame('-10.00', $balances['2210']);
        $this->assertSame('0.00', $balances['2260']);
        $this->assertSame('2026-03-20', JournalEntry::where('reference', 'EC-APP-202603-ANN')->sole()->date->toDateString());
    }

    #[Test]
    public function paying_a_selection_from_an_account_writes_one_entry_and_cancelling_reopens_all(): void
    {
        Account::create(['organization_id' => $this->org->id, 'code' => '1000', 'name' => 'Caisse', 'type' => AccountType::Asset->value]);
        $claims = [$this->draft($this->anna, '2026-03-10', '10.00'), $this->draft($this->owner, '2026-03-20', '20.00'), $this->draft($this->anna, '2026-04-01', '5.00')];
        app(Claims::class)->approve($claims);
        $before = JournalEntry::count();

        app(Claims::class)->pay($claims, '2026-04-30', '1000');

        $this->assertSame($before + 1, JournalEntry::count());
        $pay = JournalEntry::where('reference', 'EC-PAY-20260430')->sole();
        $this->assertEquals([
            ['code' => '1000', 'debit' => '0.00', 'credit' => '35.00'],
            ['code' => '2210', 'debit' => '15.00', 'credit' => '0.00'],
            ['code' => '2260', 'debit' => '20.00', 'credit' => '0.00'],
        ], $this->lines($pay->id)->all());
        $this->assertSame(Claim::STATUS_SETTLED, $claims[2]->fresh()->status);

        $this->assertSame(3, app(Claims::class)->cancelPayment($claims[1]->fresh()));

        foreach ($claims as $claim) {
            $this->assertSame(Claim::STATUS_APPROVED, $claim->fresh()->status);
        }
        $balances = $this->balances();
        $this->assertSame('0.00', $balances['1000']);
        $this->assertSame('-15.00', $balances['2210']);
    }

    #[Test]
    public function passing_a_selection_to_debt_writes_one_entry_and_one_record_per_person(): void
    {
        $owner2 = Person::create(['organization_id' => $this->org->id, 'name' => 'Second owner', 'is_owner' => true]);
        $claims = [$this->draft($this->owner, '2025-05-10', '10.00'), $this->draft($owner2, '2025-06-10', '20.00'), $this->draft($this->anna, '2025-07-10', '5.00')];
        app(Claims::class)->approve($claims);
        $before = JournalEntry::count();

        $records = app(Debts::class)->convert($claims, '2025-12-31');

        $this->assertCount(3, $records);
        $this->assertSame($before + 1, JournalEntry::count());
        $entry = JournalEntry::where('reference', 'EC-DEBT-20251231')->sole();
        $this->assertEquals([
            ['code' => '2260', 'debit' => '10.00', 'credit' => '0.00'],
            ['code' => '2260', 'debit' => '20.00', 'credit' => '0.00'],
            ['code' => '2560', 'debit' => '0.00', 'credit' => '10.00'],
            ['code' => '2560', 'debit' => '0.00', 'credit' => '20.00'],
        ], $this->lines($entry->id)->sortBy(fn ($l) => $l['code'].$l['debit'].$l['credit'])->values()->all()); // staff stays on 2210: no line
        $this->assertNull(DebtRecord::where('person_id', $this->anna->id)->value('journal_entry_id'));

        // Cancelling one owner's record counter-books only their lines.
        app(Debts::class)->cancel(DebtRecord::where('person_id', $owner2->id)->sole());
        $balances = $this->balances();
        $this->assertSame('-10.00', $balances['2560']);
        $this->assertSame('-20.00', $balances['2260']);
        $this->assertSame(Claim::STATUS_APPROVED, $claims[1]->fresh()->status);
        $this->assertSame(Claim::STATUS_DEBT, $claims[0]->fresh()->status);
    }

    #[Test]
    public function a_claim_dated_after_the_debt_date_is_refused(): void
    {
        $claim = $this->draft($this->owner, '2026-01-10', '10.00');
        app(Claims::class)->approve($claim);

        $this->expectException(\DomainException::class);
        app(Debts::class)->convert([$claim], '2025-12-31');
    }

    #[Test]
    public function the_salary_entry_debits_one_line_per_liability_account(): void
    {
        $claims = [$this->draft($this->anna, '2026-03-10', '10.00'), $this->draft($this->anna, '2026-03-12', '15.00')];
        app(Claims::class)->approve($claims);

        /** @var SalarySlip $slip */
        $slip = app(GeneratePayrollRunAction::class)->execute($this->org->id, 3, 2026, true, [], [[
            'employee_id' => $this->employee->id,
            'reimbursement_item_ids' => array_map(fn (Claim $c): string => 'claim:'.$c->id, $claims),
        ]])->first();

        $lines = $this->lines((string) $slip->fresh()->journal_entry_id)->where('code', '2210')->values();
        $this->assertEquals([['code' => '2210', 'debit' => '25.00', 'credit' => '0.00']], $lines->all());
        $this->assertSame('0.00', $this->balances()['2210']); // booked at approval, cleared by the salary
        $this->assertSame('25.00', $this->balances()['6640']); // the expense is counted once
    }

    #[Test]
    public function undoing_every_claim_of_a_draft_deletes_it(): void
    {
        $a = $this->draft($this->anna, '2026-03-10', '10.00');
        $b = $this->draft($this->anna, '2026-03-11', '20.00');
        app(Claims::class)->approve([$a, $b], null, true);
        $this->assertSame(0, JournalEntry::where('is_posted', true)->count());

        app(Claims::class)->unapprove($a->fresh());
        app(Claims::class)->unapprove($b->fresh());
        $this->assertSame(0, JournalEntry::count()); // nothing left to post by mistake
    }

    #[Test]
    public function undoing_part_of_a_draft_rewrites_the_draft(): void
    {
        $a = $this->draft($this->owner, '2025-03-10', '10.00');
        $b = $this->draft($this->anna, '2025-03-11', '20.00');
        app(Claims::class)->approve([$a, $b], null, true);

        app(Claims::class)->unapprove($a->fresh());

        $entry = JournalEntry::sole(); // one draft, rewritten with Anna's claim only
        $this->assertFalse($entry->is_posted);
        $this->assertSame('EC-APP-202503', $entry->reference);
        $this->assertSame($entry->id, $b->fresh()->journal_entry_id);
        $this->assertEquals([
            ['code' => '2210', 'debit' => '0.00', 'credit' => '20.00'],
            ['code' => '6640', 'debit' => '20.00', 'credit' => '0.00'],
        ], $this->lines($entry->id)->all());

        app(Claims::class)->unapprove($b->fresh()); // last claim: the draft is deleted
        $this->assertSame(0, JournalEntry::count());
    }

    #[Test]
    public function a_debt_record_of_a_draft_entry_is_taken_out_of_the_draft(): void
    {
        $owner2 = Person::create(['organization_id' => $this->org->id, 'name' => 'Second owner', 'is_owner' => true]);
        $claims = [$this->draft($this->owner, '2025-05-10', '10.00'), $this->draft($owner2, '2025-06-10', '20.00')];
        app(Claims::class)->approve($claims);
        app(Debts::class)->convert($claims, '2025-12-31', null, true);

        app(Debts::class)->cancel(DebtRecord::where('person_id', $this->owner->id)->sole());

        $entry = JournalEntry::where('is_posted', false)->sole();
        $this->assertSame($entry->id, DebtRecord::where('person_id', $owner2->id)->value('journal_entry_id'));
        $this->assertEquals([
            ['code' => '2260', 'debit' => '20.00', 'credit' => '0.00'],
            ['code' => '2560', 'debit' => '0.00', 'credit' => '20.00'],
        ], $this->lines($entry->id)->all());
    }

    #[Test]
    public function a_claim_cannot_be_paid_before_its_date(): void
    {
        $claim = $this->draft($this->anna, '2026-03-10', '10.00');
        app(Claims::class)->approve($claim);

        $this->expectException(\DomainException::class);
        app(Claims::class)->pay($claim, '2026-03-01');
    }

    #[Test]
    public function undoing_the_claims_of_a_group_one_by_one_reverses_each_part_once(): void
    {
        $a = $this->draft($this->anna, '2026-03-10', '10.00');
        $b = $this->draft($this->owner, '2026-03-11', '20.00');
        app(Claims::class)->approve([$a, $b]);

        app(Claims::class)->unapprove($a->fresh());
        app(Claims::class)->unapprove($b->fresh());

        $this->assertSame([], array_filter($this->balances(), fn (string $b): bool => $b !== '0.00'));
    }
}
