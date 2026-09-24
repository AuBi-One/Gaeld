<?php

namespace Plugins\ExpenseClaims\Tests;

require_once __DIR__.'/ExpenseClaimsTestCase.php';

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Actions\UnpostPayrollAction;
use App\Domains\Payroll\Models\SalarySlip;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRepayment;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Place;
use Plugins\ExpenseClaims\Models\VehicleRate;
use Plugins\ExpenseClaims\Services\Accounts;
use Plugins\ExpenseClaims\Services\Claims;
use Plugins\ExpenseClaims\Services\ClosingCheck;
use Plugins\ExpenseClaims\Services\Debts;
use Plugins\ExpenseClaims\Services\Journal;
use Spatie\Activitylog\Models\Activity;

/**
 * Database review (docs/REVIEW-database.md, F1–F7): foreign keys, tenancy and
 * uniqueness of the plugin tables, and how the code reads a reference that
 * the database nulled.
 */
class SchemaIntegrityTest extends ExpenseClaimsTestCase
{
    private Person $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = Person::create(['organization_id' => $this->org->id, 'name' => 'Anna Muster', 'employee_id' => $this->employee->id]);
    }

    private function claim(string $amount = '10.00', bool $approve = true): Claim
    {
        $claim = app(Claims::class)->saveDraft($this->org->id, [
            'person_id' => $this->anna->id, 'date' => '2026-03-10', 'title' => 'Repas', 'lines' => [['type' => 'meal', 'amount' => $amount]],
        ]);
        if ($approve) {
            app(Claims::class)->approve($claim);
        }

        return $claim->fresh();
    }

    /** @return array<string, string> account code => balance (debit - credit), posted entries only */
    private function balances(): array
    {
        return TransactionLine::query()
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->where('accounts.organization_id', $this->org->id)->where('journal_entries.is_posted', true)
            ->selectRaw('accounts.code, SUM(transaction_lines.debit) - SUM(transaction_lines.credit) AS balance')
            ->groupBy('accounts.code')->pluck('balance', 'code')
            ->map(fn ($b) => number_format((float) $b, 2, '.', ''))->all();
    }

    #[Test]
    public function a_deleted_entry_nulls_the_reference_and_the_claim_counts_as_unbooked(): void
    {
        // Approved before D37: its own approval entry, still a draft, then deleted in the journal.
        $claim = $this->claim();
        $entry = app(Journal::class)->book($this->org->id, new JournalEntryData('2026-03-10', 'EC-0001', 'Legacy approval', [
            new JournalLineData(app(Accounts::class)->id($this->org->id, '6640'), '10.00', '0'),
            new JournalLineData(app(Accounts::class)->id($this->org->id, '2210'), '0', '10.00'),
        ]), true);
        $claim->forceFill(['liability_account_code' => '2210', 'journal_entry_id' => $entry->id])->save();
        $this->assertTrue($claim->fresh()->isBooked());

        app(LedgerService::class)->deleteDraft($entry);

        $claim = $claim->fresh();
        $this->assertNull($claim->journal_entry_id); // ON DELETE SET NULL
        $this->assertFalse($claim->isBooked());       // not "posted": the booking is gone
        $findings = app(ClosingCheck::class)->check($this->org->id, '2026-01-01', '2026-12-31');
        $this->assertSame(['expense-claims.unpaid', 'expense-claims.entry-lost'], array_column($findings, 'key'));
        $this->assertTrue($findings[0]['blocking'] ?? false);

        // Paying it books the cost on the expense account, not on the lost liability.
        app(Claims::class)->pay($claim, '2026-03-31');
        $balances = $this->balances();
        $this->assertSame('10.00', $balances['6640']);
        $this->assertArrayNotHasKey('2210', $balances);

        // A deleted payment entry: the payment can still be cancelled (nothing to counter-book).
        $paid = $claim->fresh();
        DB::table('journal_entries')->where('id', $paid->settlement_entry_id)->delete();
        $this->assertNull($paid->fresh()->settlement_entry_id);
        $this->assertSame(1, app(Claims::class)->cancelPayment($paid->fresh()));
        $this->assertSame(Claim::STATUS_APPROVED, $paid->fresh()->status);
    }

    #[Test]
    public function money_transitions_are_in_the_activity_log(): void
    {
        $claim = $this->claim();
        app(Claims::class)->pay($claim, '2026-03-31');
        app(Claims::class)->cancelPayment($claim->fresh());
        app(Debts::class)->convert([$claim->fresh()], '2026-12-31');

        $events = Activity::query()
            ->where('subject_type', Claim::class)->where('subject_id', $claim->id)
            ->orderBy('id')->pluck('event')->all();
        $this->assertSame(['created', 'updated', 'approved', 'paid', 'payment_cancelled', 'passed_to_debt'], $events); // 'updated': the total is set after the lines
        $this->assertSame($this->org->id, Activity::query()->where('event', 'paid')->value('properties')['organization_id'] ?? null);
    }

    #[Test]
    public function a_debt_record_and_its_repayment_survive_a_deleted_entry(): void
    {
        $claim = $this->claim('30.00');
        $debt = app(Debts::class)->convert([$claim], '2026-12-31')->sole();
        $repayment = app(Debts::class)->repay($debt, '2027-01-15', '10.00');
        $this->assertSame($this->org->id, $repayment->organization_id); // F3: tenant column, set on create

        DB::table('journal_entries')->whereIn('id', [$debt->journal_entry_id, $repayment->journal_entry_id])->delete();

        $this->assertNull($debt->fresh()->journal_entry_id);
        $this->assertNull($repayment->fresh()->journal_entry_id);
        $this->assertSame('20.00', $debt->fresh()->load('repayments')->remaining());
        $this->assertSame(1, DebtRepayment::query()->count()); // scoped to the organisation
    }

    #[Test]
    public function a_salary_slip_that_paid_claims_cannot_be_deleted_until_it_is_unposted(): void
    {
        $claim = $this->claim();
        /** @var SalarySlip $slip */
        $slip = app(GeneratePayrollRunAction::class)->execute($this->org->id, 3, 2026, true, [], [[
            'employee_id' => $this->employee->id, 'reimbursement_item_ids' => ['claim:'.$claim->id],
        ]])->firstOrFail();
        $this->assertSame($slip->id, $claim->fresh()->salary_slip_id);

        try {
            DB::transaction(fn () => $slip->delete()); // savepoint: the failed statement does not abort the test transaction
            $this->fail('The slip was deleted although a claim refers to it.');
        } catch (QueryException) {
        }
        $this->assertSame(Claim::STATUS_SETTLED, $claim->fresh()->status);

        app(UnpostPayrollAction::class)->execute($slip->fresh());
        $this->assertNull($claim->fresh()->salary_slip_id);
        $this->assertTrue((bool) $slip->fresh()->delete());
    }

    #[Test]
    public function one_rate_per_vehicle_type_and_start_date(): void
    {
        $this->actAsOrg()->post('/settings/expense-claims/rates', ['vehicle_type' => 'car', 'valid_from' => '2027-01-01', 'rate_per_km' => '0.80'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actAsOrg()->post('/settings/expense-claims/rates', ['vehicle_type' => 'car', 'valid_from' => '2027-01-01', 'rate_per_km' => '0.85'])
            ->assertSessionHasErrors('valid_from');
        $this->assertSame(1, VehicleRate::query()->where('valid_from', '2027-01-01')->count());

        $this->expectException(QueryException::class); // and the database refuses it too
        DB::transaction(fn () => VehicleRate::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'vehicle_type' => 'car', 'valid_from' => '2027-01-01', 'rate_per_km' => '0.90']));
    }

    #[Test]
    public function a_force_deleted_contact_unlinks_places_and_people(): void
    {
        $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
        $place = Place::create(['organization_id' => $this->org->id, 'kind' => 'client', 'label' => 'Client', 'contact_id' => $contact->id]);
        $person = Person::create(['organization_id' => $this->org->id, 'name' => 'Consultant', 'contact_id' => $contact->id]);

        $contact->forceDelete();

        $this->assertNull($place->fresh()->contact_id);
        $this->assertNull($person->fresh()->contact_id);
        $this->assertSame('Client', $place->fresh()->label);
    }

    #[Test]
    public function the_plugin_migrations_roll_back_and_forward(): void
    {
        $foreignKeys = fn (string $table): array => array_column(Schema::getForeignKeys($table), 'columns');
        $indexes = fn (string $table): array => array_column(Schema::getIndexes($table), 'name');
        $this->assertContains(['journal_entry_id'], $foreignKeys('ec_claims'));

        // migrate:reset with the plugin path rolls back every plugin migration (found by name), nothing else.
        $this->artisan('migrate:reset', ['--path' => 'plugins/expense-claims/migrations', '--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('ec_claims'));
        $this->assertTrue(Schema::hasTable('journal_entries'));

        $this->artisan('migrate', ['--path' => 'plugins/expense-claims/migrations', '--force' => true])->assertSuccessful();
        $this->assertContains(['journal_entry_id'], $foreignKeys('ec_claims'));
        $this->assertContains(['settlement_entry_id'], $foreignKeys('ec_claims'));
        $this->assertContains(['contact_id'], $foreignKeys('ec_places'));
        $this->assertContains(['organization_id'], $foreignKeys('ec_debt_repayments'));
        $this->assertContains('ec_vehicle_rates_organization_id_vehicle_type_valid_from_unique', $indexes('ec_vehicle_rates'));
        $this->assertNotContains('ec_vehicle_rates_organization_id_vehicle_type_valid_from_index', $indexes('ec_vehicle_rates'));

        // Each step is reversible on its own: back down to the create migration and up again.
        $this->artisan('migrate:rollback', ['--path' => 'plugins/expense-claims/migrations', '--step' => 5, '--force' => true])->assertSuccessful();
        $this->assertNotContains(['journal_entry_id'], $foreignKeys('ec_claims'));
        $this->assertFalse(Schema::hasColumn('ec_debt_repayments', 'organization_id'));
        $this->assertContains('ec_vehicle_rates_organization_id_vehicle_type_valid_from_index', $indexes('ec_vehicle_rates'));
        $this->artisan('migrate', ['--path' => 'plugins/expense-claims/migrations', '--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('ec_debt_repayments', 'organization_id'));
    }
}
