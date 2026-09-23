<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Accounting\DTOs\JournalEntryReference;
use App\Domains\Accounting\Services\JournalEntryReferences;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\DebtRepayment;

/**
 * Tells the journal which entries belong to expense claims, so they are
 * changed through the claims and balances screens, not in the journal.
 */
final class JournalOwners
{
    public static function register(JournalEntryReferences $references): void
    {
        $references->register(fn (array $entryIds): array => self::claims($entryIds));
        $references->registerColumn(DebtRecord::class, 'journal_entry_id', fn (DebtRecord $debt): JournalEntryReference => new JournalEntryReference(
            __('expense-claims::ec.journal_source_debt', ['person' => $debt->person->name ?? '—', 'date' => $debt->date->format('d.m.Y')]),
            route('expense-balances.index'),
        ), ['person']);
        $references->registerColumn(DebtRepayment::class, 'journal_entry_id', fn (DebtRepayment $repayment): JournalEntryReference => new JournalEntryReference(
            __('expense-claims::ec.journal_source_repayment', ['date' => $repayment->date->format('d.m.Y')]),
            route('expense-balances.index'),
        ));
    }

    /**
     * Claims share an entry when approved or paid together: the label lists them all.
     *
     * @param  list<string>  $entryIds
     * @return array<string, JournalEntryReference>
     */
    private static function claims(array $entryIds): array
    {
        $claims = Claim::query()
            ->where(fn ($q) => $q->whereIn('journal_entry_id', $entryIds)->orWhereIn('settlement_entry_id', $entryIds))
            ->orderBy('number')
            ->get(['id', 'number', 'journal_entry_id', 'settlement_entry_id']);

        $byEntry = [];
        foreach ($claims as $claim) {
            foreach (array_unique(array_filter([$claim->journal_entry_id, $claim->settlement_entry_id])) as $entryId) {
                if (in_array($entryId, $entryIds, true)) {
                    $byEntry[$entryId][] = $claim;
                }
            }
        }

        return array_map(fn (array $group): JournalEntryReference => new JournalEntryReference(
            trans_choice('expense-claims::ec.journal_source_claims', count($group), [
                'refs' => implode(', ', array_map(fn (Claim $claim): string => $claim->reference(), $group)),
            ]),
            route('expense-claims.show', $group[0]),
        ), $byEntry);
    }
}
