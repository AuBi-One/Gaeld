<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DTOs\JournalEntryReference;
use App\Domains\Accounting\Models\JournalEntry;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Registry of the records that own journal entries.
 *
 * Features (core and plugins) that create journal entries register a
 * resolver; a journal entry with an owner can then be posted from the
 * journal, but not edited, deleted or reversed there: the owning feature
 * does that (owners call LedgerService directly, not the journal policy).
 *
 * Registered in core: salary slips, invoices and invoice payments (their
 * posted entries are then reversed by cancelling the invoice, not with the
 * journal's Reverse). Other features whose entries are always posted
 * (expenses, bank transactions, depreciation, VAT) are protected by the
 * posted check and may register to show their link.
 */
final class JournalEntryReferences
{
    /** @var list<Closure(list<string>): array<string, JournalEntryReference>> */
    private array $resolvers = [];

    /**
     * Register a resolver: given journal entry ids, return the owner per entry id.
     *
     * @param  Closure(list<string>): array<string, JournalEntryReference>  $resolver
     */
    public function register(Closure $resolver): self
    {
        $this->resolvers[] = $resolver;

        return $this;
    }

    /**
     * Register a model whose `$column` holds a journal entry id.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  Closure(TModel): JournalEntryReference  $describe
     * @param  array<int|string, string|Closure>  $with  Relations to eager-load for $describe (as for Builder::with)
     */
    public function registerColumn(string $model, string $column, Closure $describe, array $with = []): self
    {
        return $this->register(function (array $entryIds) use ($model, $column, $describe, $with): array {
            $owners = [];
            foreach ($model::query()->with($with)->whereIn($column, $entryIds)->get() as $record) {
                /** @var TModel $record */
                $owners[(string) $record->getAttribute($column)] ??= $describe($record);
            }

            return $owners;
        });
    }

    public function for(JournalEntry $entry): ?JournalEntryReference
    {
        return $this->forMany([(string) $entry->id])[(string) $entry->id] ?? null;
    }

    /**
     * @param  iterable<string>  $entryIds
     * @return array<string, JournalEntryReference> Owner per entry id (entries without an owner are absent)
     */
    public function forMany(iterable $entryIds): array
    {
        $ids = array_values(array_unique(array_map('strval', [...$entryIds])));
        if ($ids === []) {
            return [];
        }

        $owners = [];
        foreach ($this->resolvers as $resolver) {
            foreach ($resolver($ids) as $entryId => $reference) {
                $owners[$entryId] ??= $reference;
            }
        }

        return $owners;
    }
}
