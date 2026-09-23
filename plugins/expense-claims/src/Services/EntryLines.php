<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Accounting\DTOs\JournalLineData;
use App\Support\Money;
use Illuminate\Support\Collection;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\ClaimLine;

/**
 * Builds the grouped lines of the plugin's entries: one line per expense
 * account, and one line per liability/debt account and person (each person's
 * balance stays readable on the account).
 */
final class EntryLines
{
    /** Line descriptions are limited to 255 characters. */
    private const MAX = 255;

    public function __construct(private Accounts $accounts) {}

    /**
     * One line per expense account of the claims' lines.
     *
     * @param  Collection<int, Claim>  $claims  with lines loaded
     * @param  'debit'|'credit'  $side
     * @return list<JournalLineData>
     */
    public function expenses(string $organizationId, Collection $claims, string $side): array
    {
        return array_values($claims
            ->flatMap(fn (Claim $c) => $c->lines->map(fn (ClaimLine $l): array => ['claim' => $c, 'line' => $l]))
            ->groupBy(fn (array $x): string => (string) $x['line']->expense_account_code)
            ->map(fn (Collection $group, string $code): JournalLineData => $this->line(
                $organizationId,
                $code,
                Money::sumAmounts($group->map(fn (array $x): array => ['amount' => (string) $x['line']->amount])->values()->all()),
                $side,
                $this->references($group->pluck('claim')->unique('id')),
            ))
            ->all());
    }

    /**
     * One line per account (given by $account for each claim) and person, for
     * the claims' totals.
     *
     * @param  Collection<int, Claim>  $claims  with person loaded
     * @param  callable(Claim): string  $account
     * @param  'debit'|'credit'  $side
     * @return list<JournalLineData>
     */
    public function perPerson(string $organizationId, Collection $claims, callable $account, string $side): array
    {
        return array_values($claims
            ->groupBy(fn (Claim $c): string => $account($c).'|'.$c->person_id)
            ->map(fn (Collection $group, string $key): JournalLineData => $this->line(
                $organizationId,
                explode('|', $key)[0],
                Money::sumAmounts($group->map(fn (Claim $c): array => ['amount' => (string) $c->total])->values()->all()),
                $side,
                $group->first()?->person?->name.' — '.$this->references($group),
            ))
            ->all());
    }

    /**
     * The cost side when claims leave the company (paid or passed to debt):
     * the expense account(s) of their lines, or, for a claim whose cost was
     * booked earlier (approved before D37, or migrated without --book), the
     * liability it was booked to.
     *
     * @param  Collection<int, Claim>  $claims  with lines and person loaded
     * @param  'debit'|'credit'  $side
     * @return list<JournalLineData>
     */
    public function costs(string $organizationId, Collection $claims, string $side): array
    {
        [$booked, $open] = $claims->partition(fn (Claim $c): bool => $c->liability_account_code !== null);

        return [
            ...($open->isEmpty() ? [] : $this->expenses($organizationId, $open->values(), $side)),
            ...($booked->isEmpty() ? [] : $this->perPerson($organizationId, $booked->values(), fn (Claim $c): string => (string) $c->liability_account_code, $side)),
        ];
    }

    /**
     * Cost per account of one claim, for the payroll entry.
     *
     * @return list<array{account_code: string, amount: string}>
     */
    public static function costSplits(Claim $claim): array
    {
        if ($claim->liability_account_code !== null) {
            return [['account_code' => $claim->liability_account_code, 'amount' => Money::normalize((string) $claim->total)]];
        }

        return array_values($claim->lines
            ->groupBy(fn (ClaimLine $l): string => (string) $l->expense_account_code)
            ->map(fn (Collection $g, string $code): array => [
                'account_code' => $code,
                'amount' => Money::sumAmounts($g->map(fn (ClaimLine $l): array => ['amount' => (string) $l->amount])->values()->all()),
            ])
            ->all());
    }

    /** Short tag of a person for references (first three letters). */
    public static function tag(string $name): string
    {
        $tag = mb_strtoupper(mb_substr(preg_replace('/[^\pL]/u', '', $name) ?? '', 0, 3));

        return $tag !== '' ? $tag : 'X';
    }

    /**
     * @param  Collection<int, Claim>  $claims
     */
    public function references(Collection $claims): string
    {
        return mb_strimwidth('Notes de frais '.$claims->map(fn (Claim $c): string => $c->reference())->implode(', '), 0, self::MAX, '…');
    }

    /**
     * @param  'debit'|'credit'  $side
     */
    private function line(string $organizationId, string $code, string $amount, string $side, string $description): JournalLineData
    {
        $id = $this->accounts->id($organizationId, $code);
        $description = mb_strimwidth($description, 0, self::MAX, '…');

        return $side === 'debit'
            ? new JournalLineData($id, $amount, '0', $description)
            : new JournalLineData($id, '0', $amount, $description);
    }
}
