<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Services\LedgerService;

/**
 * Writes the plugin's journal entries, posted or as drafts, and undoes them:
 * a posted entry with a counter-entry dated like it (same period), a draft
 * by rewriting it without the undone part. Plugin entries are undone from
 * the claim screens, not with the journal's "Reverse".
 */
final class Journal
{
    public function __construct(private LedgerService $ledger, private Accounts $accounts) {}

    public function book(string $organizationId, JournalEntryData $entry, bool $draft = false): JournalEntry
    {
        return $draft ? $this->ledger->createDraft($organizationId, $entry) : $this->ledger->postEntry($organizationId, $entry);
    }

    /** The entry when it still exists and is a draft (not posted). */
    public function draft(?string $entryId): ?JournalEntry
    {
        $entry = $this->find($entryId);

        return $entry !== null && ! $entry->is_posted ? $entry : null;
    }

    /**
     * Replace a draft by one with the given lines (same date, reference and
     * description), or delete it when no lines remain. Used to take a part
     * out of a draft instead of adding a draft counter-entry that could be
     * left unposted.
     *
     * @param  list<JournalLineData>  $lines
     * @return string|null the id of the new draft
     */
    public function replaceDraft(JournalEntry $draft, array $lines): ?string
    {
        $orgId = (string) $draft->organization_id;
        $this->ledger->deleteDraft($draft);
        if ($lines === []) {
            return null;
        }

        return (string) $this->ledger->createDraft($orgId, new JournalEntryData(
            date: $draft->date->toDateString(),
            reference: $draft->reference,
            description: $draft->description,
            lines: $lines,
            type: $draft->type,
        ))->id;
    }

    /** Undo a whole posted entry with a counter-entry. Nothing happens when the entry no longer exists. */
    public function undoWhole(?string $entryId, string $description): void
    {
        $original = $this->find($entryId);
        if ($original === null) {
            return;
        }

        $lines = $original->lines->map(fn (TransactionLine $l): JournalLineData => new JournalLineData(
            (string) $l->account_id, (string) $l->debit, (string) $l->credit, $l->description,
        ))->all();
        $this->counter($original, $description, array_values($lines));
    }

    /**
     * Undo part of an entry: $lines are the lines as they were booked (they are
     * swapped here).
     *
     * @param  list<JournalLineData>  $lines
     */
    public function undoPart(?string $entryId, string $description, array $lines): void
    {
        $original = $this->find($entryId);
        if ($original !== null && $lines !== []) {
            $this->counter($original, $description, $lines);
        }
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function counter(JournalEntry $original, string $description, array $lines): void
    {
        $orgId = (string) $original->organization_id;
        $this->book($orgId, new JournalEntryData(
            date: $original->date->toDateString(),
            reference: $this->accounts->uniqueReference($orgId, mb_substr((string) $original->reference, 0, 40).'-ANN'),
            description: $description,
            lines: array_map(fn (JournalLineData $l): JournalLineData => new JournalLineData($l->accountId, $l->credit, $l->debit, $l->description), $lines),
            type: 'reversal',
        ), ! $original->is_posted);
    }

    private function find(?string $entryId): ?JournalEntry
    {
        return $entryId === null ? null : JournalEntry::withoutGlobalScopes()->with('lines')->find($entryId);
    }
}
