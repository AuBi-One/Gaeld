<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalEntryReference;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\JournalEntryReferences;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Payroll\Actions\UnpostPayrollAction;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Draft journal entries owned by another record (a salary slip, a plugin record)
 * can be posted from the journal, but not edited or deleted there.
 */
class JournalEntryOwnerTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    private Account $bank;

    private Account $salaries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->bank = Account::create(['organization_id' => $this->org->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        $this->salaries = Account::create(['organization_id' => $this->org->id, 'code' => '5000', 'name' => 'Salaries', 'type' => AccountType::Expense->value]);
    }

    public function test_journal_refuses_to_delete_or_edit_a_salary_slip_draft(): void
    {
        [$slip, $entry] = $this->slipWithDraftEntry();

        $this->actAsOrg()->delete("/accounting/journal-entries/{$entry->id}")
            ->assertRedirect('/accounting/journal-entries')
            ->assertSessionHas('error', fn (string $message) => str_starts_with($message, 'Created by Salary slip March 2026 — Max Muster.'));
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);

        $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}", [
            'date' => '2026-03-31',
            'reference' => 'CHANGED',
            'description' => 'Changed',
            'lines' => [
                ['account_id' => $this->salaries->id, 'debit' => '1.00', 'credit' => '0.00'],
                ['account_id' => $this->bank->id, 'debit' => '0.00', 'credit' => '1.00'],
            ],
        ])->assertSessionHas('error');
        $this->assertFalse($this->user->can('update', $entry));
        $this->assertSame('SLIP-DRAFT', $entry->fresh()->reference);
        $this->assertSame($entry->id, $slip->fresh()->journal_entry_id);
    }

    public function test_the_draft_can_be_posted_and_the_slip_keeps_its_entry(): void
    {
        [$slip, $entry] = $this->slipWithDraftEntry();

        $this->actAsOrg()->post("/accounting/journal-entries/{$entry->id}/post")->assertRedirect();

        $this->assertTrue($entry->fresh()->is_posted);
        $this->assertSame($entry->id, $slip->fresh()->journal_entry_id);

        // Once posted, it is reversed by unposting the slip, not with the journal's Reverse
        $this->actAsOrg()->post("/accounting/journal-entries/{$entry->id}/reverse")->assertSessionHas('error');
        $this->assertSame(0, JournalEntry::where('reference', 'like', 'REV-%')->count());

        app(UnpostPayrollAction::class)->execute($slip->fresh());
        $this->assertSame(1, JournalEntry::where('reference', 'REV-SLIP-DRAFT')->where('is_posted', true)->count());
    }

    public function test_the_label_names_a_former_employee(): void
    {
        [$slip, $entry] = $this->slipWithDraftEntry();
        $slip->forceFill(['employee_snapshot' => null])->save();
        Employee::whereKey($slip->employee_id)->firstOrFail()->delete();

        $this->assertSame('Salary slip March 2026 — Max Muster', app(JournalEntryReferences::class)->for($entry)?->label);
    }

    public function test_the_list_and_the_detail_page_show_the_owner(): void
    {
        [$slip, $owned] = $this->slipWithDraftEntry();
        $free = $this->draft('FREE-DRAFT');

        $this->actAsOrg()->get('/accounting/journal-entries')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.data', function ($rows) use ($owned, $free, $slip): bool {
                $byId = collect($rows)->keyBy('id');

                return str_starts_with((string) $byId[$owned->id]['source']['label'], 'Salary slip')
                    && str_ends_with((string) $byId[$owned->id]['source']['url'], "/payroll/salary-slips/{$slip->id}")
                    && $byId[$free->id]['source'] === null;
            }));

        $this->actAsOrg()->get("/accounting/journal-entries/{$owned->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('source.label', fn (string $label) => str_contains($label, 'Max Muster')));
        $this->actAsOrg()->get("/accounting/journal-entries/{$free->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('source', null));
    }

    public function test_a_draft_without_owner_can_still_be_edited_and_deleted(): void
    {
        $free = $this->draft('FREE-DRAFT');

        $this->assertTrue($this->user->can('update', $free));
        $this->actAsOrg()->delete("/accounting/journal-entries/{$free->id}")->assertRedirect();
        $this->assertDatabaseMissing('journal_entries', ['id' => $free->id]);
    }

    public function test_plugins_register_their_own_owners(): void
    {
        $entry = $this->draft('PLUGIN-DRAFT');
        app(JournalEntryReferences::class)->register(fn (array $ids): array => in_array((string) $entry->id, $ids, true)
            ? [(string) $entry->id => new JournalEntryReference('Expense claim EC-0007', '/expense-claims/x')]
            : []);

        $response = $this->user->can('delete', $entry);
        $this->assertFalse($response);
        $this->assertSame(
            'Created by Expense claim EC-0007. Manage it there.',
            Gate::forUser($this->user)->inspect('delete', $entry)->message(),
        );
    }

    public function test_no_ids_means_no_query_and_no_owner(): void
    {
        $this->assertSame([], app(JournalEntryReferences::class)->forMany([]));
    }

    public function test_unposting_a_slip_whose_entry_is_still_a_draft_deletes_the_draft(): void
    {
        [$slip, $entry] = $this->slipWithDraftEntry();

        $this->actAsOrg()->get("/payroll/salary-slips/{$slip->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('slip.journal_entry.is_posted', false));

        $this->actAsOrg()->post("/payroll/salary-slips/{$slip->id}/unpost")
            ->assertRedirect("/payroll/salary-slips/{$slip->id}")
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'draft journal entry was deleted')
                && str_contains($message, 'rebuilds the entry'));

        $slip->refresh();
        $this->assertNull($slip->posted_at);
        $this->assertNull($slip->journal_entry_id);
        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);
        $this->assertSame(0, JournalEntry::where('reference', 'like', 'REV-%')->count());
    }

    public function test_unposting_a_slip_with_a_posted_entry_keeps_the_usual_message(): void
    {
        [$slip, $entry] = $this->slipWithDraftEntry();
        app(LedgerService::class)->postDraft($entry);

        $this->actAsOrg()->post("/payroll/salary-slips/{$slip->id}/unpost")
            ->assertSessionHas('success', 'Salary slip unposted. You can now correct or delete it.');
        $this->assertSame(1, JournalEntry::where('reference', 'REV-SLIP-DRAFT')->count());
    }

    /** @return array{0: SalarySlip, 1: JournalEntry} */
    private function slipWithDraftEntry(): array
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ]);
        $entry = $this->draft('SLIP-DRAFT');
        $slip = app(PayrollCalculator::class)->calculate($employee, 3, 2026);
        $slip->fill(['journal_entry_id' => $entry->id, 'posted_at' => now()])->save();

        return [$slip, $entry];
    }

    private function draft(string $reference): JournalEntry
    {
        return app(LedgerService::class)->createDraft($this->org->id, new JournalEntryData(
            date: '2026-03-31',
            reference: $reference,
            description: 'Draft',
            lines: [
                $this->journalLine($this->salaries, '100.00', '0.00'),
                $this->journalLine($this->bank, '0.00', '100.00'),
            ],
        ));
    }
}
