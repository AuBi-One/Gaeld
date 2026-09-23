<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Journal entries screen: filters, sorting and page size (JournalEntryQuery).
 */
class JournalEntryListTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    private Account $bank;

    private Account $revenue;

    private Account $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->bank = $this->account('1020', 'Bank', AccountType::Asset);
        $this->revenue = $this->account('3000', 'Revenue', AccountType::Revenue);
        $this->expense = $this->account('6500', 'Office', AccountType::Expense);
    }

    public function test_default_list_is_newest_first_with_amount_and_defaults(): void
    {
        $this->sale('2026-03-01', 'A-1', 'First sale', '100.00');
        $this->sale('2026-03-05', 'A-2', 'Second sale', '250.00');

        $this->list()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Accounting/JournalEntries')
            ->where('entries.total', 2)
            ->where('entries.per_page', 20)
            ->where('entries.data.0.reference', 'A-2')
            ->where('entries.data.1.reference', 'A-1')
            ->where('entries.data.0.amount', fn ($amount) => (float) $amount === 250.0)
            ->has('entries.data.0.lines', 2)
            ->where('filters.sort', 'date')
            ->where('filters.direction', 'desc')
            ->where('filters.per_page', 20)
            ->where('perPageOptions', [20, 50, 100, 200]));
    }

    public function test_date_range_is_inclusive(): void
    {
        $this->sale('2026-02-28', 'D-1', 'Before', '10.00');
        $this->sale('2026-03-01', 'D-2', 'From day', '10.00');
        $this->sale('2026-03-31', 'D-3', 'To day', '10.00');
        $this->sale('2026-04-01', 'D-4', 'After', '10.00');

        $this->assertReferences(['from' => '2026-03-01', 'to' => '2026-03-31'], ['D-3', 'D-2']);
        $this->assertReferences(['from' => '2026-03-31'], ['D-4', 'D-3']);
        $this->assertReferences(['to' => '2026-02-28'], ['D-1']);
    }

    public function test_account_filter_returns_each_entry_touching_the_account_once(): void
    {
        $this->sale('2026-03-01', 'S-1', 'Sale', '100.00');
        $this->postJournalEntry('2026-03-02', [
            $this->journalLine($this->expense, '30.00', '0.00'),
            $this->journalLine($this->expense, '20.00', '0.00'),
            $this->journalLine($this->bank, '0.00', '50.00'),
        ], 'P-1', 'Two expense lines');

        $this->assertReferences(['accounts' => (string) $this->expense->id], ['P-1']);
        $this->assertReferences(['accounts' => (string) $this->revenue->id], ['S-1']);
        $this->assertReferences(['accounts' => (string) $this->bank->id], ['P-1', 'S-1']);
    }

    public function test_several_accounts_match_entries_on_any_of_them(): void
    {
        $this->sale('2026-03-01', 'S-1', 'Sale', '100.00');
        $this->postJournalEntry('2026-03-02', [
            $this->journalLine($this->expense, '50.00', '0.00'),
            $this->journalLine($this->bank, '0.00', '50.00'),
        ], 'P-1', 'Purchase');
        $unused = $this->account('9999', 'Unused', AccountType::Expense);

        $this->assertReferences(['accounts' => "{$this->expense->id},{$this->revenue->id}"], ['P-1', 'S-1']);
        $this->assertReferences(['accounts' => "{$this->expense->id},{$unused->id}"], ['P-1']);
        $this->assertReferences(['accounts' => "{$this->bank->id},{$this->expense->id},{$this->revenue->id}"], ['P-1', 'S-1']);
        $this->assertReferences(['accounts' => (string) $unused->id], []);
        $this->list(['accounts' => "{$this->expense->id},x,,{$this->expense->id}"])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.accounts', [$this->expense->id]));
        $this->list(['accounts' => implode(',', range(1, 60))])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.accounts', range(1, 50)));
        // Filters combine with AND
        $this->assertReferences(['accounts' => "{$this->expense->id},{$this->revenue->id}", 'description' => 'purchase'], ['P-1']);
    }

    public function test_reference_and_description_contain_case_insensitive_with_literal_wildcards(): void
    {
        $this->sale('2026-03-01', 'INV-2026-001', 'Consulting March', '100.00');
        $this->sale('2026-03-02', 'INV-2026-002', 'Licence fee', '100.00');
        $this->sale('2026-03-03', 'BANK_7', '50% deposit', '100.00');

        $this->assertReferences(['reference' => 'inv-2026'], ['INV-2026-002', 'INV-2026-001']);
        $this->assertReferences(['reference' => '001'], ['INV-2026-001']);
        $this->assertReferences(['reference' => '_'], ['BANK_7']);
        $this->assertReferences(['description' => 'CONSULT'], ['INV-2026-001']);
        $this->assertReferences(['description' => '%'], ['BANK_7']);
        $this->assertReferences(['reference' => 'inv', 'description' => 'licence'], ['INV-2026-002']);

        $this->sale('2026-03-04', 'WITH\\SLASH', 'Backslash', '100.00');
        $this->postJournalEntry('2026-03-05', [
            $this->journalLine($this->expense, '10.00', '0.00', 'Printer toner'),
            $this->journalLine($this->bank, '0.00', '10.00'),
        ], 'LINE-1', 'Office supplies');
        $this->assertReferences(['description' => 'toner'], ['LINE-1']);
        $this->assertReferences(['description' => 'office'], ['LINE-1']);
        $this->assertReferences(['description' => 'toner', 'reference' => 'INV'], []);
        $this->assertReferences(['reference' => '\\'], ['WITH\\SLASH']);
    }

    public function test_status_filter_separates_drafts_and_posted_entries(): void
    {
        $this->sale('2026-03-01', 'POSTED-1', 'Posted', '100.00');
        app(LedgerService::class)->createDraft($this->organization->id, new JournalEntryData(
            date: '2026-03-02',
            reference: 'DRAFT-1',
            description: 'Draft',
            lines: [
                $this->journalLine($this->bank, '5.00', '0.00'),
                $this->journalLine($this->revenue, '0.00', '5.00'),
            ],
        ));

        $this->assertReferences(['status' => 'draft'], ['DRAFT-1']);
        $this->assertReferences(['status' => 'posted'], ['POSTED-1']);
        $this->assertReferences([], ['DRAFT-1', 'POSTED-1']);
        $this->assertReferences(['sort' => 'status', 'direction' => 'asc'], ['DRAFT-1', 'POSTED-1']);
        $this->assertReferences(['sort' => 'status', 'direction' => 'desc'], ['POSTED-1', 'DRAFT-1']);
    }

    public function test_every_column_sorts_both_ways(): void
    {
        $this->sale('2026-03-02', 'B', 'charlie', '300.00');
        $this->sale('2026-03-01', 'C', 'alpha', '100.00');
        $this->sale('2026-03-03', 'A', 'bravo', '200.00');

        $this->assertReferences(['sort' => 'date', 'direction' => 'asc'], ['C', 'B', 'A']);
        $this->assertReferences(['sort' => 'reference', 'direction' => 'asc'], ['A', 'B', 'C']);
        $this->assertReferences(['sort' => 'reference', 'direction' => 'desc'], ['C', 'B', 'A']);
        $this->assertReferences(['sort' => 'description', 'direction' => 'asc'], ['C', 'A', 'B']);
        $this->assertReferences(['sort' => 'amount', 'direction' => 'desc'], ['B', 'A', 'C']);
        $this->assertReferences(['sort' => 'amount', 'direction' => 'asc'], ['C', 'A', 'B']);
        $this->assertReferences(['sort' => 'description', 'direction' => 'desc'], ['B', 'A', 'C']);

        // Empty descriptions come last in both directions
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-03-04',
            reference: 'N',
            description: null,
            lines: [
                $this->journalLine($this->bank, '1.00', '0.00'),
                $this->journalLine($this->revenue, '0.00', '1.00'),
            ],
        ));
        $this->assertReferences(['sort' => 'description', 'direction' => 'asc'], ['C', 'A', 'B', 'N']);
        $this->assertReferences(['sort' => 'description', 'direction' => 'desc'], ['B', 'A', 'C', 'N']);
    }

    public function test_page_size_is_limited_to_the_offered_options(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->sale('2026-03-01', sprintf('P-%02d', $i), 'Entry', '1.00');
        }

        $this->list()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.per_page', 20)->where('entries.last_page', 2)->has('entries.data', 20));
        $this->list(['per_page' => 50])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.per_page', 50)->where('entries.last_page', 1)->has('entries.data', 21)
            ->where('entries.next_page_url', null));
        $this->list(['sort' => 'reference', 'direction' => 'asc', 'page' => 2, 'reference' => 'P-'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries.data', 1)
            ->where('entries.prev_page_url', fn (string $url) => str_contains($url, 'reference=P-')
                && str_contains($url, 'sort=reference') && str_contains($url, 'direction=asc')));
        $this->list(['per_page' => 50, 'reference' => 'P-'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.first_page_url', fn (string $url) => str_contains($url, 'per_page=50')));

        // Same date for all: the tie-breakers keep pages disjoint and complete
        $seen = [];
        foreach ([1, 2] as $pageNumber) {
            $this->list(['page' => $pageNumber])->assertInertia(function (AssertableInertia $page) use (&$seen) {
                $page->where('entries.data', function ($rows) use (&$seen) {
                    array_push($seen, ...collect($rows)->pluck('reference')->all());

                    return true;
                });
            });
        }
        $this->assertCount(21, array_unique($seen));
        $this->list(['per_page' => 7])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.per_page', 20)->where('filters.per_page', 20));
    }

    public function test_invalid_parameters_are_ignored(): void
    {
        $this->sale('2026-03-01', 'X-1', 'Entry', '1.00');

        $this->list([
            'from' => '2026-02-31',
            'to' => 'yesterday',
            'accounts' => '1 OR 1=1',
            'status' => 'deleted',
            'sort' => 'organization_id',
            'direction' => 'sideways',
            'reference' => ['array'],
            'per_page' => ['50'],
        ])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.total', 1)
            ->where('filters.from', null)
            ->where('filters.to', null)
            ->where('filters.accounts', [])
            ->where('filters.status', null)
            ->where('filters.sort', 'date')
            ->where('filters.direction', 'desc')
            ->where('filters.reference', ''));

        $this->list(['sort' => ['amount'], 'accounts' => ['1'], 'direction' => ['asc']])->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entries.total', 1)
                ->where('filters.sort', 'date')
                ->where('filters.accounts', []));
    }

    public function test_actions_return_to_the_filtered_list(): void
    {
        $draft = app(LedgerService::class)->createDraft($this->organization->id, new JournalEntryData(
            date: '2026-03-02',
            reference: 'DRAFT-1',
            description: 'Draft',
            lines: [
                $this->journalLine($this->bank, '5.00', '0.00'),
                $this->journalLine($this->revenue, '0.00', '5.00'),
            ],
        ));
        $filtered = url('/accounting/journal-entries?status=draft&sort=reference&page=2');

        $this->actAsOrg()->from($filtered)
            ->post("/accounting/journal-entries/{$draft->id}/post")
            ->assertRedirect($filtered);

        $this->actAsOrg()->from(url('/accounting/journal-entries/create'))
            ->post("/accounting/journal-entries/{$draft->id}/reverse")
            ->assertRedirect('/accounting/journal-entries');
    }

    public function test_filter_accounts_include_inactive_accounts(): void
    {
        $this->account('1099', 'Old suspense', AccountType::Asset)->update(['is_active' => false]);

        $this->list()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('filterAccounts', 4)
            ->has('accounts', 3));
    }

    public function test_entries_of_other_organizations_are_not_listed(): void
    {
        $this->sale('2026-03-01', 'MINE', 'Mine', '1.00');
        $other = Organization::factory()->create();
        app(CurrentOrganization::class)->set($other);
        $otherBank = $other->accounts()->create(['code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        $otherRevenue = $other->accounts()->create(['code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);
        app(LedgerService::class)->postEntry($other->id, new JournalEntryData(
            date: '2026-03-01',
            reference: 'THEIRS',
            description: 'Theirs',
            lines: [
                $this->journalLine($otherBank, '1.00', '0.00'),
                $this->journalLine($otherRevenue, '0.00', '1.00'),
            ],
        ));
        app(CurrentOrganization::class)->set($this->organization);

        $this->assertReferences([], ['MINE']);
        $this->assertReferences(['reference' => 'THEIRS'], []);
    }

    public function test_export_without_filters_keeps_posted_entries_of_the_year(): void
    {
        $this->travelTo('2026-06-30');
        $this->sale('2025-12-31', 'OLD', 'Last year', '10.00');
        $this->sale('2026-03-01', 'POSTED', 'Posted', '10.00');
        $this->draft('2026-03-02', 'DRAFT');

        $csv = $this->exportCsv([]);
        $this->assertStringContainsString('POSTED', $csv);
        $this->assertStringNotContainsString('DRAFT', $csv);
        $this->assertStringNotContainsString('OLD', $csv);
    }

    public function test_export_applies_the_list_filters(): void
    {
        $this->sale('2026-03-01', 'S-1', 'Consulting', '10.00');
        $this->postJournalEntry('2026-03-02', [
            $this->journalLine($this->expense, '5.00', '0.00', 'Printer toner'),
            $this->journalLine($this->bank, '0.00', '5.00'),
        ], 'P-1', 'Office');
        $this->draft('2026-03-03', 'DRAFT-1');

        $byAccount = $this->exportCsv(['from' => '2026-01-01', 'to' => '2026-12-31', 'accounts' => (string) $this->expense->id]);
        $this->assertStringContainsString('P-1', $byAccount);
        $this->assertStringNotContainsString('S-1', $byAccount);

        $byReference = $this->exportCsv(['from' => '2026-01-01', 'to' => '2026-12-31', 'reference' => 's-']);
        $this->assertStringContainsString('S-1', $byReference);
        $this->assertStringNotContainsString('P-1', $byReference);

        $byText = $this->exportCsv(['from' => '2026-01-01', 'to' => '2026-12-31', 'description' => 'toner']);
        $this->assertStringContainsString('P-1', $byText);
        $this->assertStringNotContainsString('S-1', $byText);

        $drafts = $this->exportCsv(['from' => '2026-01-01', 'to' => '2026-12-31', 'status' => 'draft']);
        $this->assertStringContainsString('DRAFT-1', $drafts);
        $this->assertStringContainsString(';draft', $drafts);
        $this->assertStringNotContainsString('S-1', $drafts);

        $this->actAsOrg()->get('/accounting/journal-entries/export/pdf?from=2026-01-01&to=2026-12-31&reference=S-')->assertOk();
        $this->actAsOrg()->get('/accounting/journal-entries/export/pdf?from=2026-01-01&to=2026-12-31&status=draft')->assertOk();
    }

    /** @param array<string, string> $query */
    private function exportCsv(array $query): string
    {
        $response = $this->actAsOrg()->get('/accounting/journal-entries/export/csv?'.http_build_query($query));
        $response->assertOk();

        return (string) $response->streamedContent();
    }

    private function draft(string $date, string $reference): void
    {
        app(LedgerService::class)->createDraft($this->organization->id, new JournalEntryData(
            date: $date,
            reference: $reference,
            description: 'Draft',
            lines: [
                $this->journalLine($this->bank, '5.00', '0.00'),
                $this->journalLine($this->revenue, '0.00', '5.00'),
            ],
        ));
    }

    /** @param array<string, mixed> $query */
    private function list(array $query = []): TestResponse
    {
        return $this->actAsOrg()->get('/accounting/journal-entries?'.http_build_query($query));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>  $expected
     */
    private function assertReferences(array $query, array $expected): void
    {
        $this->list($query)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.data', fn ($rows) => collect($rows)->pluck('reference')->all() === $expected));
    }

    private function sale(string $date, string $reference, string $description, string $amount): void
    {
        $this->postJournalEntry($date, [
            $this->journalLine($this->bank, $amount, '0.00'),
            $this->journalLine($this->revenue, '0.00', $amount),
        ], $reference, $description);
    }

    private function account(string $code, string $name, AccountType $type): Account
    {
        return Account::create([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => $name,
            'type' => $type->value,
        ]);
    }
}
