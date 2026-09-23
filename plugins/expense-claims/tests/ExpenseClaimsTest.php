<?php

namespace Plugins\ExpenseClaims\Tests;

require_once __DIR__.'/ExpenseClaimsTestCase.php';

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Actions\PostPayrollAction;
use App\Domains\Payroll\Actions\UnpostPayrollAction;
use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Domains\Payroll\Models\SalarySlip;
use App\Support\Plugins\PluginNavigation;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\Distance;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Place;
use Plugins\ExpenseClaims\Services\Claims;
use Plugins\ExpenseClaims\Services\ClosingCheck;
use Plugins\ExpenseClaims\Services\Debts;
use Plugins\ExpenseClaims\Services\Rates;

class ExpenseClaimsTest extends ExpenseClaimsTestCase
{
    private function person(bool $owner = false, bool $employee = true): Person
    {
        return Person::create([
            'organization_id' => $this->org->id,
            'name' => $owner ? 'Owner' : 'Anna Muster',
            'employee_id' => $employee ? $this->employee->id : null,
            'is_owner' => $owner,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function claim(Person $person, string $date, array $lines, bool $approve = true): Claim
    {
        $claim = app(Claims::class)->saveDraft($this->org->id, [
            'person_id' => $person->id,
            'date' => $date,
            'title' => 'Séance client',
            'lines' => $lines,
        ]);

        if ($approve) {
            app(Claims::class)->approve($claim);
        }

        return $approve ? $claim->fresh() : $claim;
    }

    /** @return DebtRecord the person's debt record for their approved claims up to $date */
    private function convert(Person $person, string $date): DebtRecord
    {
        $claims = Claim::where('person_id', $person->id)->where('status', Claim::STATUS_APPROVED)->whereDate('date', '<=', $date)->get();

        return app(Debts::class)->convert($claims, $date)->sole();
    }

    /** @return array<string, string> account code => balance (debit - credit) */
    private function balances(): array
    {
        return TransactionLine::query()
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->where('accounts.organization_id', $this->org->id)
            ->selectRaw('accounts.code, SUM(transaction_lines.debit) - SUM(transaction_lines.credit) AS balance')
            ->groupBy('accounts.code')
            ->pluck('balance', 'code')
            ->map(fn ($b) => number_format((float) $b, 2, '.', ''))
            ->all();
    }

    #[Test]
    public function km_rate_depends_on_the_trip_year_and_rounds_to_five_centimes(): void
    {
        $rates = app(Rates::class);
        $this->assertSame('0.7000', $rates->rateFor($this->org->id, 'car', '2025-12-31'));
        $this->assertSame('0.7500', $rates->rateFor($this->org->id, 'car', '2026-01-01'));
        $this->assertSame('8.60', Rates::kmAmount('12.3', '0.7000')); // 8.61 → 8.60
        $this->assertSame('9.25', Rates::kmAmount('12.3', '0.7500')); // 9.225 → 9.25
        $this->assertSame('84.00', Rates::kmAmount('120', '0.7000'));
    }

    #[Test]
    public function approving_books_nothing_and_paying_books_the_cost_against_the_bank(): void
    {
        $staff = $this->claim($this->person(), '2026-03-10', [
            ['type' => 'km', 'km' => '100', 'round_trip' => false],
            ['type' => 'meal', 'amount' => '32.50'],
        ]);
        $owner = $this->claim($this->person(owner: true, employee: false), '2026-03-11', [['type' => 'other', 'amount' => '10.00']]);
        $this->assertSame(0, JournalEntry::count()); // D37: approval only records who and when
        $this->assertSame(Claim::STATUS_APPROVED, $staff->status);

        app(Claims::class)->pay([$staff, $owner], '2026-03-31');

        $balances = $this->balances();
        $this->assertSame('117.50', $balances['6640']);   // 100 km × 0.75 + 32.50 + 10
        $this->assertSame('-117.50', $balances['1020']);
        $this->assertArrayNotHasKey('2210', $balances);
        $this->assertSame(2, JournalEntry::count());     // one entry per person
        $this->assertSame('Frais de déplacement', Account::where('code', '6640')->value('name'));
    }

    #[Test]
    public function unapproving_returns_to_draft_without_any_entry(): void
    {
        $claim = $this->claim($this->person(), '2026-03-10', [['type' => 'meal', 'amount' => '20.00']]);
        app(Claims::class)->unapprove($claim);

        $this->assertSame(Claim::STATUS_DRAFT, $claim->fresh()->status);
        $this->assertNull($claim->fresh()->approved_at);
        $this->assertSame(0, JournalEntry::count());
    }

    #[Test]
    public function payroll_run_reimburses_selected_claims_from_their_liability_and_unpost_reopens_them(): void
    {
        $person = $this->person();
        $paid = $this->claim($person, '2026-03-10', [['type' => 'meal', 'amount' => '30.00']]);
        $kept = $this->claim($person, '2026-03-12', [['type' => 'meal', 'amount' => '15.00']]);

        $slips = app(GeneratePayrollRunAction::class)->execute($this->org->id, 3, 2026, true, [], [[
            'employee_id' => $this->employee->id,
            'reimbursement_amount' => '5.00',
            'reimbursement_item_ids' => ['claim:'.$paid->id],
        ]]);
        /** @var SalarySlip $slip */
        $slip = $slips->first();

        $this->assertSame('35.00', $slip->deductions['reimbursement_amount']);
        $this->assertSame(Claim::STATUS_SETTLED, $paid->fresh()->status);
        $this->assertSame('payroll', $paid->fresh()->settled_via);
        $this->assertSame(Claim::STATUS_APPROVED, $kept->fresh()->status);
        $balances = $this->balances();
        $this->assertSame('30.00', $balances['6640']);    // the paid claim's cost, booked with the salary (D37)
        $this->assertSame('5.00', $balances['6530']);     // manual remainder keeps the core account
        $this->assertArrayNotHasKey('2210', $balances);   // no accrual

        app(UnpostPayrollAction::class)->execute($slip->fresh());
        $this->assertSame(Claim::STATUS_APPROVED, $paid->fresh()->status);
        $this->assertNull($paid->fresh()->salary_slip_id);
        $this->assertSame('0.00', $this->balances()['6640']);
    }

    #[Test]
    public function posting_fails_when_a_selected_claim_was_paid_meanwhile(): void
    {
        $claim = $this->claim($this->person(), '2026-03-10', [['type' => 'meal', 'amount' => '30.00']]);
        $slip = app(GeneratePayrollRunAction::class)->execute($this->org->id, 3, 2026, false, [], [[
            'employee_id' => $this->employee->id,
            'reimbursement_item_ids' => ['claim:'.$claim->id],
        ]])->first();
        app(Claims::class)->pay($claim, '2026-03-20');

        $this->expectException(\DomainException::class);
        app(PostPayrollAction::class)->execute($slip);
    }

    #[Test]
    public function period_end_conversion_moves_owner_claims_to_the_long_term_debt_and_can_be_repaid(): void
    {
        $owner = $this->person(owner: true, employee: false);
        $this->claim($owner, '2025-11-10', [['type' => 'km', 'km' => '100']]); // 2025 → 0.70
        $this->claim($owner, '2026-01-05', [['type' => 'meal', 'amount' => '20.00']]);

        $debt = $this->convert($owner, '2025-12-31');

        $this->assertSame('70.00', (string) $debt->amount);
        $this->assertSame('2560', $debt->account_code);
        $balances = $this->balances();
        $this->assertSame('70.00', $balances['6640']);   // cost booked on the debt date; the 2026 claim is not booked yet
        $this->assertSame('-70.00', $balances['2560']);
        $this->assertTrue(JournalEntry::where('reference', 'like', 'EC-DEBT-20251231%')->whereDate('date', '2025-12-31')->exists());

        app(Debts::class)->repay($debt, '2026-02-01', '50.00');
        $this->assertSame('20.00', $debt->fresh()->load('repayments')->remaining());
        $this->assertSame('-20.00', $this->balances()['2560']);
    }

    #[Test]
    public function cancelling_a_debt_record_reopens_its_claims(): void
    {
        $owner = $this->person(owner: true, employee: false);
        $claim = $this->claim($owner, '2025-11-10', [['type' => 'meal', 'amount' => '20.00']]);
        $debt = $this->convert($owner, '2025-12-31');

        app(Debts::class)->cancel($debt);

        $this->assertSame(Claim::STATUS_APPROVED, $claim->fresh()->status);
        $this->assertSame('0.00', $this->balances()['6640']);
        $this->assertSame('0.00', $this->balances()['2560']);
    }

    #[Test]
    public function staff_debt_is_booked_on_the_staff_account(): void
    {
        $person = $this->person();
        $this->claim($person, '2025-11-10', [['type' => 'meal', 'amount' => '20.00']]);

        $debt = $this->convert($person, '2025-12-31');

        $this->assertSame('2210', $debt->account_code);
        $this->assertNotNull($debt->journal_entry_id);
        $this->assertSame('-20.00', $this->balances()['2210']);
        $this->assertSame('20.00', $this->balances()['6640']);
    }

    #[Test]
    public function km_differing_from_the_looked_up_distance_needs_a_reason(): void
    {
        $hq = Place::create(['organization_id' => $this->org->id, 'kind' => 'hq', 'label' => 'Office', 'lat' => 46.5, 'lon' => 6.6]);
        $client = Place::create(['organization_id' => $this->org->id, 'kind' => 'client', 'label' => 'Client', 'lat' => 46.2, 'lon' => 7.0]);
        Distance::create(['from_place_id' => $hq->id, 'to_place_id' => $client->id, 'km' => '50.0', 'provider' => 'ors']);
        $person = $this->person();
        $trip = ['type' => 'km', 'from_place_id' => $client->id, 'to_place_id' => $hq->id, 'round_trip' => true];

        $claim = $this->claim($person, '2026-03-10', [$trip + ['km' => '100']], approve: false);
        $this->assertSame('lookup', $claim->lines->first()->km_source);
        $this->assertSame('75.00', (string) $claim->total);

        $claim = $this->claim($person, '2026-03-10', [$trip + ['km' => '110', 'km_override_reason' => 'Detour via Aigle']], approve: false);
        $this->assertSame('manual', $claim->lines->first()->km_source);

        $this->expectException(ValidationException::class);
        $this->claim($person, '2026-03-10', [$trip + ['km' => '110']], approve: false);
    }

    #[Test]
    public function closing_check_reports_drafts_and_unpaid_claims_of_the_period(): void
    {
        $person = $this->person();
        $this->claim($person, '2025-06-01', [['type' => 'meal', 'amount' => '20.00']], approve: false);
        $this->claim($person, '2025-07-01', [['type' => 'meal', 'amount' => '30.00']]);
        $this->claim($person, '2026-01-10', [['type' => 'meal', 'amount' => '99.00']]);

        $findings = app(ClosingCheck::class)->check($this->org->id, '2025-01-01', '2025-12-31');

        $this->assertSame(['expense-claims.drafts', 'expense-claims.unpaid'], array_column($findings, 'key'));
        $this->assertStringContainsString('30.00', $findings[1]['message']);
        $this->assertSame('/expense-balances?status=approved&to=2025-12-31', $findings[1]['action_url']);
    }

    #[Test]
    public function http_flow_creates_approves_and_lists_a_claim(): void
    {
        $person = $this->person();

        $this->actAsOrg()->post('/expense-claims', [
            'person_id' => $person->id,
            'date' => '2026-04-02',
            'title' => 'Séance de travail',
            'lines' => [
                ['type' => 'km', 'km' => '42', 'round_trip' => false],
                ['type' => 'meal', 'amount' => '28.00'],
            ],
        ])->assertRedirect('/expense-balances')->assertSessionHasNoErrors(); // someone else's claim: back to the balances

        $claim = Claim::firstOrFail();
        $this->assertSame('59.50', (string) $claim->total); // 42 × 0.75 = 31.50 + 28

        $this->actAsOrg()->post("/expense-claims/{$claim->id}/approve")->assertRedirect();
        $this->assertSame(Claim::STATUS_APPROVED, $claim->fresh()->status);
        $this->assertSame($this->user->id, $claim->fresh()->approved_by);
        $this->assertNotNull($claim->fresh()->approved_at);

        $this->actAsOrg()->get('/expense-claims')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('ExpenseClaims/Index', false)
                ->where('hasPerson', false) // the owner's own claims only; Anna's are not listed
                ->where('claims.total', 0)
                ->where('translations.ec_nav_claims', 'Expense claims'));

        $this->actAsOrg()->get('/expense-balances')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('ExpenseClaims/Balances', false)
                ->where('claims.0.id', $claim->id)
                ->where('people.0.approved_total', '59.50'));

        $this->actAsOrg()->get('/payroll/run')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where("reimbursementItems.{$this->employee->id}.0.id", 'claim:'.$claim->id));

        $this->assertContains(['expenses', '/expense-claims'], array_map(fn ($i) => [$i['parent'], $i['href']], app(PluginNavigation::class)->toArray()));
    }

    #[Test]
    public function other_organisations_cannot_see_a_claim(): void
    {
        $claim = $this->claim($this->person(), '2026-03-10', [['type' => 'meal', 'amount' => '20.00']]);
        $other = Organization::factory()->create();
        $this->organization->users()->detach($this->user->id);
        $other->users()->attach($this->user->id, ['role' => 'owner']);
        $this->assignOrganizationRole($this->user, $other, 'owner');

        $this->app->forgetScopedInstances(); // as in a fresh request: the org comes from the session

        $this->actingAs($this->user)->withSession(['current_organization_id' => $other->id])
            ->get("/expense-claims/{$claim->id}")
            ->assertNotFound();
    }

    #[Test]
    public function settle_refuses_a_claim_that_is_no_longer_open_for_the_booked_amount(): void
    {
        $claim = $this->claim($this->person(), '2026-03-10', [['type' => 'meal', 'amount' => '30.00']]);
        $slip = app(GeneratePayrollRunAction::class)->execute($this->org->id, 3, 2026, false, [], [[
            'employee_id' => $this->employee->id,
            'reimbursement_item_ids' => ['claim:'.$claim->id],
        ]])->first();
        $items = $slip->adjustments['reimbursement_items'];
        app(Claims::class)->pay($claim, '2026-03-20');

        $this->expectException(\DomainException::class);
        app(ReimbursementSourceInterface::class)->settle($slip, $items);
    }

    #[Test]
    public function closing_check_also_reports_unpaid_claims_of_earlier_years(): void
    {
        $this->claim($this->person(), '2024-05-01', [['type' => 'meal', 'amount' => '12.00']]);
        $this->claim($this->person(owner: true, employee: false), '2024-05-02', [['type' => 'meal', 'amount' => '8.00']], approve: false);

        $findings = app(ClosingCheck::class)->check($this->org->id, '2025-01-01', '2025-12-31');

        $this->assertSame(['expense-claims.unpaid'], array_column($findings, 'key')); // old draft not reported
        $this->assertStringContainsString('12.00', $findings[0]['message']);
    }

    #[Test]
    public function people_come_from_employees_or_members_and_the_form_defaults_to_the_current_user(): void
    {
        $this->actAsOrg()->post('/settings/expense-claims/people', ['user_id' => $this->user->id, 'is_owner' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actAsOrg()->post('/settings/expense-claims/people', ['employee_id' => $this->employee->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actAsOrg()->post('/settings/expense-claims/people', [])->assertSessionHasErrors(['user_id', 'employee_id']);

        $member = Person::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame($this->user->name, $member->name);
        $this->assertSame('Anna Muster', Person::where('employee_id', $this->employee->id)->value('name'));

        $this->actAsOrg()->get('/expense-claims/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('defaultPersonId', $member->id));
    }

    #[Test]
    public function a_home_place_can_be_created_for_a_person(): void
    {
        $person = $this->person();
        $this->actAsOrg()->post('/settings/expense-claims/places', [
            'kind' => 'home', 'label' => 'Domicile Anna', 'address' => 'Rue 1', 'postal_code' => '1000', 'city' => 'Lausanne',
            'person_id' => $person->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(Place::where('label', 'Domicile Anna')->value('id'), $person->fresh()->home_place_id);
    }
}
