<?php

namespace Plugins\ExpenseClaims\Tests;

require_once __DIR__.'/ExpenseClaimsTestCase.php';

use App\Domains\Accounting\Models\JournalEntry;
use PHPUnit\Framework\Attributes\Test;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Place;

class ImportAirtableTest extends ExpenseClaimsTestCase
{
    private function file(string $name, array $records): string
    {
        $path = sys_get_temp_dir()."/ec-{$name}-".uniqid().'.json';
        file_put_contents($path, json_encode(['records' => $records]));

        return $path;
    }

    #[Test]
    public function it_imports_claims_splitting_shared_ones_and_recomputing_2025_km(): void
    {
        Person::create(['organization_id' => $this->org->id, 'name' => 'Alice', 'is_owner' => true]);
        Person::create(['organization_id' => $this->org->id, 'name' => 'Bob', 'is_owner' => true]);
        Place::create(['organization_id' => $this->org->id, 'kind' => 'hq', 'label' => 'Bureau']);
        Place::create(['organization_id' => $this->org->id, 'kind' => 'client', 'label' => 'Salines de Bex']);

        $employees = $this->file('emp', [
            ['id' => 'recA', 'fields' => ['Employé' => 'Alice']],
            ['id' => 'recB', 'fields' => ['Employé' => 'Bob']],
        ]);
        $claims = $this->file('rep', [
            ['id' => 'rec1', 'fields' => ['Date' => '2025-03-01', 'Qui' => ['recA', 'recB'], 'Type' => 'Trajet', 'Titre' => 'Séance aux Salines de Bex', 'Distance' => 100, 'Montant final' => 75.0, 'Statut' => 'A payer', 'No pièce' => 1]],
            ['id' => 'rec2', 'fields' => ['Date' => '2025-04-01', 'Qui' => ['recA', 'recB'], 'Type' => 'Trajet+Repas', 'Titre' => 'Autre', 'Distance' => 10, 'Montant' => 20.0, 'Montant final' => 27.5, 'Statut' => 'A payer']],
            ['id' => 'rec3', 'fields' => ['Date' => '2026-02-01', 'Qui' => ['recA'], 'Type' => 'Repas', 'Titre' => 'Repas', 'Montant' => 30.0, 'Montant final' => 30.0, 'Statut' => 'A payer']],
        ]);

        $this->artisan('expense-claims:import-airtable', ['file' => $claims, '--employees' => $employees, '--org' => $this->org->id])
            ->assertSuccessful();
        $this->assertSame(0, Claim::count(), 'dry run writes nothing');

        $this->artisan('expense-claims:import-airtable', [
            'file' => $claims, '--employees' => $employees, '--org' => $this->org->id,
            '--commit' => true, '--post-adjustment' => '2025-12-31',
        ])->assertSuccessful();

        $claims1 = Claim::with('lines', 'person')->orderBy('date')->get();
        $this->assertCount(3, $claims1);
        $this->assertSame('70.00', (string) $claims1[0]->total);             // 100 km × 0.70
        $this->assertSame('Alice', $claims1[0]->person->name);
        $this->assertSame('Bob', $claims1[1]->person->name);                  // shared claim goes to the lower total
        $this->assertSame('27.00', (string) $claims1[1]->total);             // 10 × 0.70 + 20
        $this->assertNotNull($claims1[0]->lines->first()->to_place_id);       // "Salines de Bex" found in the title
        $this->assertNull($claims1[0]->lines->first()->round_trip);
        $this->assertSame(Claim::STATUS_APPROVED, $claims1[0]->status);
        $this->assertNull($claims1[0]->journal_entry_id);

        // corrections 5.00 (Alice) and 0.50 (Bob) posted on 31.12.2025
        $this->assertSame(2, JournalEntry::where('reference', 'like', 'EC-ADJ-20251231%')->whereDate('date', '2025-12-31')->count());

        // idempotent
        $this->artisan('expense-claims:import-airtable', ['file' => $claims, '--employees' => $employees, '--org' => $this->org->id, '--commit' => true])
            ->assertSuccessful();
        $this->assertSame(3, Claim::count());
    }

    #[Test]
    public function commit_needs_an_adjustment_date_when_amounts_differ_and_posts_negative_differences_reversed(): void
    {
        Person::create(['organization_id' => $this->org->id, 'name' => 'Alice', 'is_owner' => false]);
        Place::create(['organization_id' => $this->org->id, 'kind' => 'hq', 'label' => 'Bureau']);
        $employees = $this->file('emp', [['id' => 'recA', 'fields' => ['Employé' => 'Alice']]]);
        // Airtable total (60) below the recomputed one (100 km × 0.70 = 70): difference −10
        $claims = $this->file('rep', [
            ['id' => 'rec9', 'fields' => ['Date' => '2025-03-01', 'Qui' => ['recA'], 'Type' => 'Trajet', 'Titre' => 'X', 'Distance' => 100, 'Montant final' => 60.0, 'Statut' => 'A payer']],
        ]);
        $args = ['file' => $claims, '--employees' => $employees, '--org' => $this->org->id, '--commit' => true];

        $this->artisan('expense-claims:import-airtable', $args)->assertFailed();
        $this->assertSame(0, Claim::count());

        $this->artisan('expense-claims:import-airtable', $args + ['--post-adjustment' => '2025-12-31'])->assertSuccessful();
        $entry = JournalEntry::with('lines.account')->where('reference', 'like', 'EC-ADJ-%')->firstOrFail();
        $this->assertSame('10.00', number_format((float) $entry->lines->firstWhere('account.code', '6640')->debit, 2, '.', ''));
        $this->assertSame('10.00', number_format((float) $entry->lines->firstWhere('account.code', '2210')->credit, 2, '.', ''));
    }

    #[Test]
    public function qui_can_be_airtable_collaborators_instead_of_employee_links(): void
    {
        Person::create(['organization_id' => $this->org->id, 'name' => 'Alice', 'is_owner' => false]);
        Place::create(['organization_id' => $this->org->id, 'kind' => 'hq', 'label' => 'Bureau']);
        $claims = $this->file('rep', [
            ['id' => 'recC', 'fields' => ['Date' => '2026-02-01', 'Qui' => [['id' => 'usr1', 'email' => 'alice@example.com', 'name' => 'Alice']], 'Type' => 'Repas', 'Titre' => 'R', 'Montant' => 30.0, 'Montant final' => 30.0, 'Statut' => 'A payer']],
        ]);

        $this->artisan('expense-claims:import-airtable', ['file' => $claims, '--org' => $this->org->id, '--commit' => true])->assertSuccessful();

        $this->assertSame('Alice', Claim::with('person')->firstOrFail()->person->name);
    }

    #[Test]
    public function book_approves_unpaid_claims_at_the_recomputed_amount(): void
    {
        Person::create(['organization_id' => $this->org->id, 'name' => 'Alice', 'is_owner' => true]);
        Place::create(['organization_id' => $this->org->id, 'kind' => 'hq', 'label' => 'Bureau']);
        $employees = $this->file('emp', [['id' => 'recA', 'fields' => ['Employé' => 'Alice']]]);
        $claims = $this->file('rep', [
            ['id' => 'recB1', 'fields' => ['Date' => '2025-03-01', 'Qui' => ['recA'], 'Type' => 'Trajet', 'Titre' => 'X', 'Distance' => 100, 'Montant final' => 75.0, 'Statut' => 'A payer']],
            ['id' => 'recB2', 'fields' => ['Date' => '2025-04-01', 'Qui' => ['recA'], 'Type' => 'Repas', 'Titre' => 'Y', 'Montant' => 20.0, 'Montant final' => 20.0, 'Statut' => 'Payé']],
        ]);

        $this->artisan('expense-claims:import-airtable', ['file' => $claims, '--employees' => $employees, '--org' => $this->org->id, '--commit' => true, '--book' => true])
            ->assertSuccessful();

        $unpaid = Claim::where('external_ref', 'recB1')->firstOrFail();
        $this->assertSame(Claim::STATUS_APPROVED, $unpaid->status);
        $this->assertNull($unpaid->journal_entry_id);
        $this->assertNull($unpaid->liability_account_code); // not booked: the cost is booked when paid (D37)
        $this->assertSame('70.00', (string) $unpaid->total);
        $this->assertSame(Claim::STATUS_SETTLED, Claim::where('external_ref', 'recB2')->value('status'));
        $this->assertSame(0, JournalEntry::count()); // no approval entry, no correction entry
    }

    #[Test]
    public function debt_date_books_paid_claims_as_a_debt_record_per_person(): void
    {
        Person::create(['organization_id' => $this->org->id, 'name' => 'Alice', 'is_owner' => true]);
        Place::create(['organization_id' => $this->org->id, 'kind' => 'hq', 'label' => 'Bureau']);
        $employees = $this->file('emp', [['id' => 'recA', 'fields' => ['Employé' => 'Alice']]]);
        $claims = $this->file('rep', [
            ['id' => 'recD1', 'fields' => ['Date' => '2025-03-01', 'Qui' => ['recA'], 'Type' => 'Trajet', 'Titre' => 'X', 'Distance' => 100, 'Montant final' => 75.0, 'Statut' => 'Payé']],
            ['id' => 'recD2', 'fields' => ['Date' => '2025-04-01', 'Qui' => ['recA'], 'Type' => 'Repas', 'Titre' => 'Y', 'Montant' => 20.0, 'Montant final' => 20.0, 'Statut' => 'Payé']],
            ['id' => 'recD3', 'fields' => ['Date' => '2026-02-01', 'Qui' => ['recA'], 'Type' => 'Repas', 'Titre' => 'Z', 'Montant' => 30.0, 'Montant final' => 30.0, 'Statut' => 'A payer']],
            // many small claims: the debt entry's line lists them all (description capped at 255)
            ...array_map(fn (int $i): array => ['id' => "recM{$i}", 'fields' => ['Date' => '2025-06-01', 'Qui' => ['recA'], 'Type' => 'Autres', 'Titre' => 'M', 'Montant' => 1.0, 'Montant final' => 1.0, 'Statut' => 'Payé']], range(1, 40)),
        ]);
        $args = ['file' => $claims, '--employees' => $employees, '--org' => $this->org->id, '--commit' => true];

        $this->artisan('expense-claims:import-airtable', $args + ['--debt-date' => '2025-12-31'])->assertFailed();
        $this->artisan('expense-claims:import-airtable', $args + ['--book' => true, '--debt-date' => '2025-12-31'])->assertSuccessful();

        $this->assertSame(Claim::STATUS_DEBT, Claim::where('external_ref', 'recD1')->value('status'));
        $this->assertSame(Claim::STATUS_DEBT, Claim::where('external_ref', 'recD2')->value('status'));
        $this->assertSame(Claim::STATUS_APPROVED, Claim::where('external_ref', 'recD3')->value('status'));
        $debt = DebtRecord::sole();
        $this->assertSame('130.00', (string) $debt->amount); // 100 km × 0.70 + 20 + 40 × 1, not Airtable's 135
        $this->assertSame('2025-12-31', $debt->date->toDateString());
        // Only the debt: one entry for the person on the debt date, Dr 6640 · Cr 2560 (D37, D38).
        $this->assertSame(1, JournalEntry::count());
        $entry = JournalEntry::with('lines.account')->sole();
        $this->assertSame('2025-12-31', $entry->date->toDateString());
        $this->assertSame(['2560' => '-130.00', '6640' => '130.00'], $entry->lines->mapWithKeys(fn ($l) => [$l->account->code => number_format((float) $l->debit - (float) $l->credit, 2, '.', '')])->sortKeys()->all());
        $this->assertSame(0, JournalEntry::where('is_posted', false)->count());
    }

    #[Test]
    public function report_shows_the_gald_status_and_draft_writes_unposted_entries(): void
    {
        Person::create(['organization_id' => $this->org->id, 'name' => 'Alice', 'is_owner' => true]);
        Person::create(['organization_id' => $this->org->id, 'name' => 'Bob', 'is_owner' => true]);
        Place::create(['organization_id' => $this->org->id, 'kind' => 'hq', 'label' => 'Bureau']);
        $employees = $this->file('emp', [['id' => 'recA', 'fields' => ['Employé' => 'Alice']], ['id' => 'recB', 'fields' => ['Employé' => 'Bob']]]);
        $claims = $this->file('rep', [
            ['id' => 'recR1', 'fields' => ['Date' => '2025-03-01', 'Qui' => ['recA'], 'Type' => 'Repas', 'Titre' => 'X', 'Montant' => 10.0, 'Montant final' => 10.0, 'Statut' => 'Payé']],
            ['id' => 'recR2', 'fields' => ['Date' => '2025-03-05', 'Qui' => ['recB'], 'Type' => 'Repas', 'Titre' => 'Y', 'Montant' => 20.0, 'Montant final' => 20.0, 'Statut' => 'Payé']],
            ['id' => 'recR3', 'fields' => ['Date' => '2026-02-01', 'Qui' => ['recA'], 'Type' => 'Repas', 'Titre' => 'Z', 'Montant' => 30.0, 'Montant final' => 30.0, 'Statut' => 'A payer']],
            ['id' => 'recR4', 'fields' => ['Date' => '2026-03-01', 'Qui' => ['recB'], 'Type' => 'Repas', 'Titre' => 'W', 'Montant' => 5.0, 'Montant final' => 5.0, 'Statut' => 'Payé']],
        ]);
        $report = sys_get_temp_dir().'/ec-report-'.uniqid().'.md';
        $args = ['file' => $claims, '--employees' => $employees, '--org' => $this->org->id, '--book' => true, '--debt-date' => '2025-12-31', '--report' => $report];

        $this->artisan('expense-claims:import-airtable', $args)->assertSuccessful(); // dry run
        $rows = array_values(array_filter(file($report, FILE_IGNORE_NEW_LINES), fn (string $l): bool => str_starts_with($l, '| 20')));
        $status = array_map(fn (string $l): string => trim(explode('|', $l)[6]), $rows);
        $this->assertSame(['debt', 'debt', 'approved', 'paid'], $status);
        $this->assertSame('Payé', trim(explode('|', $rows[0])[8]));

        $this->artisan('expense-claims:import-airtable', $args + ['--commit' => true, '--draft' => true])->assertSuccessful();

        // One debt entry per person on the debt date, as drafts; the approved 2026 claim books nothing.
        $this->assertSame(2, JournalEntry::count());
        $this->assertSame(2, JournalEntry::where('is_posted', false)->count());
        $this->assertSame(2, DebtRecord::count());
        $this->assertSame(2, DebtRecord::query()->distinct()->count('journal_entry_id'));
        $this->assertSame(Claim::STATUS_DEBT, Claim::where('external_ref', 'recR2')->value('status'));
        $this->assertNotNull(Claim::where('external_ref', 'recR3')->value('approved_at'));
    }
}
