<?php

namespace Plugins\ExpenseClaims\Console;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Services\LedgerService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\ClaimLine;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Place;
use Plugins\ExpenseClaims\Models\Setting;
use Plugins\ExpenseClaims\Services\Accounts;
use Plugins\ExpenseClaims\Services\Claims;
use Plugins\ExpenseClaims\Services\Debts;
use Plugins\ExpenseClaims\Services\Rates;

/**
 * Imports the Airtable "Représentation" table (JSON export) as expense claims.
 *
 * Rules (docs/DESIGN-expense-claims.md §6, DECISIONS.md):
 * - one person per claim; a claim listing two people goes whole to the one
 *   with the lower running total, so both totals end roughly equal;
 * - Trajet → km line from the office (to: a place whose label appears in the
 *   title, else empty), round trip unknown, km as recorded, never re-derived;
 *   km amount always recomputed at the rate valid on the trip date
 *   (2025: 0.70, 2026: 0.75); Repas → meal, Autres → other;
 * - "A payer" → approved (no new journal entry), "Payé" → settled (migrated);
 *   with --book --debt-date=DATE, "Payé" claims up to DATE were not paid but
 *   recorded as a debt: they are booked and grouped per person into a debt
 *   record on DATE;
 * - the difference between Airtable's "Montant final" and the recomputed
 *   total of unpaid claims is reported per person and, with
 *   --post-adjustment=DATE (required when there is a difference), posted per
 *   person on DATE: Dr liability · Cr expense (or the reverse when negative).
 * Dry run unless --commit. Re-running skips records already imported.
 */
class ImportAirtableCommand extends Command
{
    protected $signature = 'expense-claims:import-airtable
        {file : Représentation JSON export ("Qui" as Employé links or as collaborators)}
        {--employees= : Employé JSON export (to resolve "Qui")}
        {--org= : Organisation id}
        {--commit : Write the claims (default: dry run)}
        {--post-adjustment= : Post the per-person correction on this date (e.g. 2025-12-31)}
        {--book : Book unpaid claims like an approval (Dr expense / Cr liability, claim date) at the recomputed amounts; for a ledger that does not hold them yet. Replaces --post-adjustment}
        {--debt-date= : With --book: Payé claims dated on or before this date were recorded as a debt in Airtable, not paid; book them and group them per person into a debt record on this date}
        {--report= : Write the detailed table to this file and print only totals (keeps personal data off the console)}';

    protected $description = 'Import Airtable expense claims (Représentation) into the expense-claims plugin';

    public function handle(Rates $rates, Accounts $accounts, LedgerService $ledger, Claims $claims, Debts $debts): int
    {
        $orgId = (string) $this->option('org');
        $records = $this->records((string) $this->argument('file'));
        $names = $this->option('employees') ? collect($this->records((string) $this->option('employees')))
            ->mapWithKeys(fn (array $r): array => [$r['id'] => trim((string) ($r['fields']['Employé'] ?? ''))])->all() : [];

        $people = Person::withoutGlobalScopes()->where('organization_id', $orgId)->get()->keyBy(fn (Person $p) => mb_strtolower($p->name));
        $places = Place::withoutGlobalScopes()->where('organization_id', $orgId)->get();
        $hq = $places->firstWhere('kind', 'hq');
        $settings = Setting::forOrganization($orgId);
        $done = Claim::withoutGlobalScopes()->where('organization_id', $orgId)->whereNotNull('external_ref')->pluck('external_ref')->flip();

        usort($records, fn (array $a, array $b): int => [$a['fields']['Date'] ?? '', $a['id']] <=> [$b['fields']['Date'] ?? '', $b['id']]);

        $running = [];
        $plan = [];
        $problems = [];
        foreach ($records as $record) {
            $f = $record['fields'];
            if (isset($done[$record['id']])) {
                continue;
            }
            // "Qui" is either links to Employé (record ids) or Airtable collaborators ({id, email, name}).
            $qui = array_map(fn ($q): string => is_array($q) ? (string) ($q['name'] ?? $q['email'] ?? '') : (string) ($names[$q] ?? $q), (array) ($f['Qui'] ?? []));
            $who = array_values(array_filter(array_map(fn (string $name) => $people[mb_strtolower(trim($name))] ?? null, $qui)));
            if ($who === [] || count($who) !== count($qui)) {
                $problems[] = "{$record['id']}: person not found (".implode(', ', $qui).')';

                continue;
            }
            $date = (string) $f['Date'];
            $lines = $this->lines($f, $date, $orgId, $rates, $settings->expense_account_code, $hq, $places);
            $total = Money::sumAmounts(array_map(fn (array $l): array => ['amount' => $l['amount']], $lines));
            usort($who, fn (Person $a, Person $b): int => Money::compare($running[$a->id] ?? '0.00', $running[$b->id] ?? '0.00'));
            $person = $who[0];
            $running[$person->id] = Money::add($running[$person->id] ?? '0.00', $total);
            $paid = ($f['Statut'] ?? '') === 'Payé';
            $airtableTotal = Money::normalize(number_format((float) ($f['Montant final'] ?? 0), 2, '.', ''));

            $plan[] = compact('record', 'person', 'date', 'lines', 'total', 'paid', 'airtableTotal');
        }

        $rows = array_map(fn (array $p): array => [
            $p['date'], $p['person']->name, mb_strimwidth((string) ($p['record']['fields']['Titre'] ?? ''), 0, 40, '…'),
            $p['airtableTotal'], $p['total'], $p['paid'] ? 'paid' : 'unpaid',
            implode(', ', array_filter(array_map(fn (array $l) => $l['to_label'] ?? null, $p['lines']))),
        ], $plan);
        $report = (string) $this->option('report');
        if ($report === '') {
            $this->table(['Date', 'Person', 'Title', 'Airtable', 'Gäld', 'Status', 'To'], $rows);
        } else {
            $out = ['| Date | Person | Title | Airtable | Gäld | Status | To |', '|---|---|---|---:|---:|---|---|'];
            foreach ($rows as $r) {
                $out[] = '| '.implode(' | ', array_map(fn ($v) => str_replace('|', '/', (string) $v), $r)).' |';
            }
            file_put_contents($report, implode("\n", $out)."\n");
        }

        $adjustments = [];
        foreach ($plan as $p) {
            if (! $p['paid']) {
                $key = $p['person']->id;
                $adjustments[$key] = Money::add($adjustments[$key] ?? '0.00', Money::subtract($p['airtableTotal'], $p['total']));
            }
        }
        foreach ($adjustments as $personId => $amount) {
            $line = sprintf('Unpaid difference Airtable − Gäld, %s: CHF %s', $people->firstWhere('id', $personId)?->name, $amount);
            $report === '' ? $this->line($line) : file_put_contents($report, "\n- {$line}", FILE_APPEND);
        }
        foreach ($problems as $problem) {
            $report === '' ? $this->warn($problem) : file_put_contents($report, "\n- PROBLEM: {$problem}", FILE_APPEND);
        }
        if ($report !== '' && $problems !== []) {
            $this->warn(count($problems).' problem(s), see '.$report);
        }

        if (! $this->option('commit')) {
            $this->info(count($plan).' claim(s) would be imported (dry run; add --commit).');

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($problems !== []) {
            $this->error('Fix the problems first (listed above, or in the --report file).');

            return self::FAILURE;
        }
        $date = (string) $this->option('post-adjustment');
        $book = (bool) $this->option('book');
        if ($book && $date !== '') {
            $this->error('--book and --post-adjustment exclude each other.');

            return self::FAILURE;
        }
        $debtDate = (string) $this->option('debt-date');
        if ($debtDate !== '' && (! $book || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $debtDate, $d) !== 1 || ! checkdate((int) $d[2], (int) $d[3], (int) $d[1]))) {
            $this->error('--debt-date needs --book and a date YYYY-MM-DD.');

            return self::FAILURE;
        }
        // Payé up to the debt date: recorded as a debt in Airtable, not paid.
        foreach ($plan as $i => $p) {
            $plan[$i]['debt'] = $debtDate !== '' && $p['paid'] && $p['date'] <= $debtDate;
            $plan[$i]['paid'] = $p['paid'] && ! $plan[$i]['debt'];
        }
        $needsAdjustment = collect($adjustments)->contains(fn (string $a): bool => ! Money::isZero($a));
        if ($needsAdjustment && $date === '' && ! $book) {
            // The difference is computed from this run only; importing without it would lose it.
            $this->error('Airtable and Gäld amounts differ: add --post-adjustment=DATE (e.g. 2025-12-31).');

            return self::FAILURE;
        }

        DB::transaction(function () use ($plan, $orgId, $settings, $adjustments, $people, $accounts, $ledger, $date, $book, $claims, $debts, $debtDate): void {
            $number = (int) Claim::withoutGlobalScopes()->where('organization_id', $orgId)->max('number');
            foreach ($plan as $p) {
                $f = $p['record']['fields'];
                $liability = $p['person']->is_owner ? $settings->owner_liability_code : $settings->staff_liability_code;
                $claim = Claim::withoutGlobalScopes()->create([
                    'organization_id' => $orgId,
                    'number' => ++$number,
                    'person_id' => $p['person']->id,
                    'date' => $p['date'],
                    'title' => (string) ($f['Titre'] ?? $f['Type'] ?? 'Airtable'),
                    'notes' => trim(implode("\n", array_filter([
                        isset($f['No pièce']) ? "Airtable n° {$f['No pièce']}" : null,
                        $f['Notes'] ?? null,
                    ]))),
                    'status' => $p['paid'] ? Claim::STATUS_SETTLED : ($book ? Claim::STATUS_DRAFT : Claim::STATUS_APPROVED),
                    'settled_via' => $p['paid'] ? 'migrated' : null,
                    'total' => $p['total'],
                    'liability_account_code' => $liability,
                    'source' => 'airtable',
                    'external_ref' => $p['record']['id'],
                ]);
                foreach ($p['lines'] as $position => $line) {
                    unset($line['to_label']);
                    ClaimLine::query()->create($line + ['claim_id' => $claim->id, 'position' => $position]);
                }
                if ($book && ! $p['paid']) {
                    $claims->approve($claim);
                }
            }

            if ($book) {
                foreach (collect($plan)->where('debt', true)->pluck('person')->unique('id') as $person) {
                    $debts->convert($person, $debtDate, 'Airtable : frais comptabilisés en dette');
                }

                return;
            }

            foreach ($adjustments as $personId => $amount) {
                if (Money::isZero($amount)) {
                    continue;
                }
                $person = $people->firstWhere('id', $personId);
                $liability = $accounts->id($orgId, $person->is_owner ? $settings->owner_liability_code : $settings->staff_liability_code);
                $expense = $accounts->id($orgId, $settings->expense_account_code);
                // Positive: Airtable booked more than is owed → Dr liability · Cr expense; negative: the reverse.
                $abs = Money::isNegative($amount) ? Money::negate($amount) : $amount;
                [$debit, $credit] = Money::isNegative($amount) ? [$expense, $liability] : [$liability, $expense];
                $ledger->postEntry($orgId, new JournalEntryData(
                    date: $date,
                    reference: $accounts->uniqueReference($orgId, 'EC-ADJ-'.str_replace('-', '', $date).'-'.mb_strtoupper(mb_substr($person->name, 0, 3))),
                    description: "Correction taux km frais non remboursés — {$person->name}",
                    lines: [
                        new JournalLineData($debit, $abs, '0', 'Correction CHF 0.75 → 0.70/km'),
                        new JournalLineData($credit, '0', $abs, $person->name),
                    ],
                ));
            }
        });

        $this->info(count($plan).' claim(s) imported.');

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return array_values($data['records'] ?? $data);
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  Collection<int, Place>  $places
     * @return list<array<string, mixed>>
     */
    private function lines(array $f, string $date, string $orgId, Rates $rates, string $account, ?Place $hq, $places): array
    {
        $type = (string) ($f['Type'] ?? 'Autres');
        $lines = [];
        if (str_contains($type, 'Trajet') && ! empty($f['Distance'])) {
            $to = $places->first(fn (Place $p): bool => $p->kind !== 'hq' && $p->kind !== 'home'
                && str_contains(mb_strtolower((string) ($f['Titre'] ?? '')), mb_strtolower($p->label)));
            $rate = $rates->rateFor($orgId, 'car', $date);
            $km = number_format((float) $f['Distance'], 1, '.', '');
            $lines[] = [
                'type' => 'km', 'description' => null, 'from_place_id' => $hq?->id, 'to_place_id' => $to?->id, 'to_label' => $to?->label,
                'round_trip' => null, 'km_lookup' => null, 'km' => $km, 'km_source' => 'migrated', 'vehicle_type' => 'car',
                'rate' => $rate, 'amount' => Rates::kmAmount($km, $rate), 'expense_account_code' => $account,
            ];
        }
        $amount = (float) ($f['Montant'] ?? 0);
        if ($amount > 0) {
            $lines[] = [
                'type' => str_contains($type, 'Repas') ? 'meal' : 'other', 'description' => null,
                'amount' => Money::normalize(number_format($amount, 2, '.', '')), 'expense_account_code' => $account,
            ];
        }

        return $lines;
    }
}
