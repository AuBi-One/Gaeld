<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\ClaimLine;
use Plugins\ExpenseClaims\Models\Distance;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Setting;

/**
 * Claim life cycle: draft → approved (booked: Dr expense · Cr liability) →
 * settled (paid with a salary or from an account) or moved into a debt
 * record. Actions on several claims write one grouped entry
 * (docs/DESIGN-expense-claims.md §8.4).
 */
final class Claims
{
    public function __construct(
        private Journal $journal,
        private Accounts $accounts,
        private Rates $rates,
        private EntryLines $lines,
    ) {}

    /**
     * Create or update a draft claim with its lines. Km amounts are always
     * computed here from km × the rate valid on the claim date.
     *
     * @param  array{person_id: string, date: string, title: string, notes?: ?string, lines: list<array<string, mixed>>}  $data
     */
    public function saveDraft(string $organizationId, array $data, ?Claim $claim = null): Claim
    {
        return DB::transaction(function () use ($organizationId, $data, $claim): Claim {
            if ($claim !== null) {
                $claim = Claim::withoutGlobalScopes()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
                if (! $claim->isDraft()) {
                    throw new \DomainException(__('expense-claims::ec.only_draft_editable'));
                }
            }

            $settings = Setting::forOrganization($organizationId);
            Person::query()->where('organization_id', $organizationId)->findOrFail($data['person_id']);

            $claim ??= new Claim([
                'organization_id' => $organizationId,
                'number' => $this->nextNumber($organizationId),
                'status' => Claim::STATUS_DRAFT,
            ]);
            $claim->fill([
                'person_id' => $data['person_id'],
                'date' => $data['date'],
                'title' => $data['title'],
                'notes' => $data['notes'] ?? null,
            ]);
            $claim->save();

            $claim->lines()->delete();
            $total = Money::zero();
            foreach ($data['lines'] as $position => $input) {
                $line = $this->buildLine($organizationId, $claim, $input, $settings->expense_account_code, $position);
                $line->position = $position;
                $line->save();
                $total = Money::add($total, (string) $line->amount);
            }

            $claim->update(['total' => $total]);

            return $claim->load('lines');
        });
    }

    /**
     * Approve drafts and book them: one entry per calendar month of the claim
     * dates, dated on the latest claim date of that month (the expense stays
     * in its period), Dr expense account(s) · Cr the liability of each person.
     *
     * @param  Claim|iterable<Claim>  $claims
     * @return Collection<int, Claim>
     */
    public function approve(Claim|iterable $claims, ?int $approverId = null, bool $draft = false): Collection
    {
        return DB::transaction(function () use ($claims, $approverId, $draft): Collection {
            $locked = $this->lockMany($claims, Claim::STATUS_DRAFT)->load(['lines', 'person']);
            $orgId = (string) $locked->first()?->organization_id;
            $settings = Setting::forOrganization($orgId);
            foreach ($locked as $claim) {
                if ($claim->lines->isEmpty() || ! Money::isPositive((string) $claim->total)) {
                    throw new \DomainException(__('expense-claims::ec.claim_empty').' ('.$claim->reference().')');
                }
                $claim->liability_account_code = $claim->person?->is_owner ? $settings->owner_liability_code : $settings->staff_liability_code;
            }

            foreach ($locked->groupBy(fn (Claim $c): string => $c->date->format('Y-m')) as $month => $group) {
                $single = $group->count() === 1 ? $group->first() : null;
                $entry = $this->journal->book($orgId, new JournalEntryData(
                    date: $group->max(fn (Claim $c): string => $c->date->toDateString()),
                    reference: $this->accounts->uniqueReference($orgId, $single?->reference() ?? 'EC-APP-'.str_replace('-', '', (string) $month)),
                    description: $single !== null
                        ? "Note de frais {$single->reference()} — {$single->person?->name}: {$single->title}"
                        : "Notes de frais {$month} — {$group->count()} notes",
                    lines: $this->approvalLines($orgId, $group),
                ), $draft);

                foreach ($group as $claim) {
                    $claim->forceFill([
                        'status' => Claim::STATUS_APPROVED,
                        'journal_entry_id' => $entry->id,
                        'approved_by' => $approverId,
                        'approved_at' => now(),
                    ])->save();
                }
            }

            return $locked;
        });
    }

    /**
     * Return an unpaid claim to draft: counter-entry for this claim's part of
     * the approval entry (always the claim's own amounts, so undoing claims of
     * a group one after the other never reverses a part twice).
     */
    public function unapprove(Claim $claim): Claim
    {
        return DB::transaction(function () use ($claim): Claim {
            // Lock every claim of the same approval entry in id order first (no deadlock between two cancels).
            $entryId = Claim::withoutGlobalScopes()->whereKey($claim->id)->value('journal_entry_id');
            if ($entryId !== null) {
                Claim::withoutGlobalScopes()->where('journal_entry_id', $entryId)->orderBy('id')->lockForUpdate()->pluck('id');
            }
            $claim = $this->locked($claim, Claim::STATUS_APPROVED)->load(['lines', 'person']);
            $description = "Annulation approbation {$claim->reference()}";
            $orgId = (string) $claim->organization_id;
            $draft = $this->journal->draft($claim->journal_entry_id);
            if ($draft !== null) {
                // Still a draft (e.g. a migration): rewrite it with the other claims only.
                $others = Claim::withoutGlobalScopes()->where('journal_entry_id', $draft->id)->whereKeyNot($claim->id)
                    ->orderBy('id')->lockForUpdate()->get()->load(['lines', 'person']);
                $newId = $this->journal->replaceDraft($draft, $this->approvalLines($orgId, $others));
                Claim::withoutGlobalScopes()->whereIn('id', $others->modelKeys())->update(['journal_entry_id' => $newId]);
            } else {
                $this->journal->undoPart($claim->journal_entry_id, $description, $this->approvalLines($orgId, new Collection([$claim])));
            }
            $claim->forceFill(['status' => Claim::STATUS_DRAFT, 'liability_account_code' => null, 'journal_entry_id' => null, 'approved_by' => null, 'approved_at' => null])->save();

            return $claim;
        });
    }

    /**
     * Pay approved claims from a bank or cash account: one entry, Dr the
     * liability of each person · Cr the account.
     *
     * @param  Claim|iterable<Claim>  $claims
     * @return Collection<int, Claim>
     */
    public function pay(Claim|iterable $claims, string $date, ?string $accountCode = null): Collection
    {
        return DB::transaction(function () use ($claims, $date, $accountCode): Collection {
            $locked = $this->lockMany($claims, Claim::STATUS_APPROVED)->load('person');
            if ($locked->contains(fn (Claim $c): bool => $c->date->toDateString() > $date)) {
                throw new \DomainException(__('expense-claims::ec.claim_after_payment_date', ['date' => $date]));
            }
            $orgId = (string) $locked->first()?->organization_id;
            $accountCode ??= Setting::forOrganization($orgId)->bank_account_code;
            $total = Money::sumAmounts($locked->map(fn (Claim $c): array => ['amount' => (string) $c->total])->values()->all());
            $single = $locked->count() === 1 ? $locked->first() : null;

            $entry = $this->journal->book($orgId, new JournalEntryData(
                date: $date,
                reference: $this->accounts->uniqueReference($orgId, $single !== null ? $single->reference().'-PAY' : 'EC-PAY-'.str_replace('-', '', $date)),
                description: $single !== null
                    ? "Remboursement {$single->reference()} — {$single->person?->name}"
                    : "Remboursement de {$locked->count()} notes de frais",
                lines: [
                    ...$this->lines->perPerson($orgId, $locked, fn (Claim $c): string => (string) $c->liability_account_code, 'debit'),
                    new JournalLineData($this->accounts->id($orgId, $accountCode), '0', $total, $this->lines->references($locked)),
                ],
            ));

            Claim::withoutGlobalScopes()->whereIn('id', $locked->modelKeys())->update([
                'status' => Claim::STATUS_SETTLED, 'settled_via' => 'bank', 'settled_on' => $date, 'settlement_entry_id' => $entry->id,
            ]);

            return $locked;
        });
    }

    /**
     * Cancel a payment from an account: the whole payment entry is undone and
     * every claim it paid is approved (unpaid) again.
     *
     * @return int the number of claims reopened
     */
    public function cancelPayment(Claim $claim): int
    {
        return DB::transaction(function () use ($claim): int {
            // Lock every claim of the payment in id order (no deadlock between two cancel clicks), then check.
            $entryId = Claim::withoutGlobalScopes()->whereKey($claim->id)->value('settlement_entry_id');
            $ids = Claim::withoutGlobalScopes()->where('organization_id', $claim->organization_id)
                ->when($entryId !== null, fn ($q) => $q->where('settlement_entry_id', $entryId), fn ($q) => $q->whereKey($claim->id))
                ->orderBy('id')->lockForUpdate()->pluck('id');
            $claim = $this->locked($claim, Claim::STATUS_SETTLED);
            if ($claim->settled_via !== 'bank' || $claim->settlement_entry_id === null || $claim->settlement_entry_id !== $entryId) {
                throw new \DomainException(__('expense-claims::ec.not_paid_by_bank'));
            }
            $this->journal->undoWhole($entryId, "Annulation remboursement {$claim->reference()}");
            Claim::withoutGlobalScopes()->whereIn('id', $ids)->update(['status' => Claim::STATUS_APPROVED, 'settled_via' => null, 'settled_on' => null, 'settlement_entry_id' => null]);

            return $ids->count();
        });
    }

    /**
     * Lines of an approval: Dr expense account(s) · Cr each person's liability.
     *
     * @param  Collection<int, Claim>  $claims  with lines and person loaded, liability_account_code set
     * @return list<JournalLineData>
     */
    private function approvalLines(string $orgId, Collection $claims): array
    {
        return $claims->isEmpty() ? [] : [
            ...$this->lines->expenses($orgId, $claims, 'debit'),
            ...$this->lines->perPerson($orgId, $claims, fn (Claim $c): string => (string) $c->liability_account_code, 'credit'),
        ];
    }

    /**
     * Delete a draft claim.
     *
     * @return list<array{path: string, name: string}> the attachments, for the caller to remove from storage
     */
    public function delete(Claim $claim): array
    {
        return DB::transaction(function () use ($claim): array {
            $claim = $this->locked($claim, Claim::STATUS_DRAFT);
            $claim->delete();

            return $claim->attachments ?? [];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function buildLine(string $organizationId, Claim $claim, array $input, string $expenseAccount, int $position): ClaimLine
    {
        $type = (string) $input['type'];
        $line = new ClaimLine([
            'claim_id' => $claim->id,
            'type' => $type,
            'description' => $input['description'] ?? null,
            'expense_account_code' => $expenseAccount,
        ]);

        if ($type !== 'km') {
            $line->amount = Money::normalize((string) $input['amount']);

            return $line;
        }

        $vehicle = (string) ($input['vehicle_type'] ?? 'car');
        $roundTrip = (bool) ($input['round_trip'] ?? false);
        $km = number_format((float) $input['km'], 1, '.', '');
        $lookup = $this->cachedKm($input['from_place_id'] ?? null, $input['to_place_id'] ?? null);
        $expected = $lookup === null ? null : number_format((float) $lookup * ($roundTrip ? 2 : 1), 1, '.', '');
        $reason = trim((string) ($input['km_override_reason'] ?? ''));

        if ($expected !== null && $expected !== $km && $reason === '') {
            throw ValidationException::withMessages([
                "lines.{$position}.km_override_reason" => __('expense-claims::ec.km_override_reason_required', ['km' => $expected]),
            ]);
        }

        try {
            $rate = $this->rates->rateFor($organizationId, $vehicle, $claim->date->toDateString());
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(["lines.{$position}.km" => $e->getMessage()]);
        }
        $line->fill([
            'from_place_id' => $input['from_place_id'] ?? null,
            'to_place_id' => $input['to_place_id'] ?? null,
            'round_trip' => $roundTrip,
            'km_lookup' => $lookup,
            'km' => $km,
            'km_source' => $expected !== null && $expected === $km ? 'lookup' : 'manual',
            'km_override_reason' => $expected !== null && $expected !== $km ? $reason : null,
            'vehicle_type' => $vehicle,
            'rate' => $rate,
            'amount' => Rates::kmAmount($km, $rate),
        ]);

        return $line;
    }

    private function cachedKm(mixed $fromId, mixed $toId): ?string
    {
        if (! is_string($fromId) || ! is_string($toId) || $fromId === '' || $toId === '') {
            return null;
        }

        $distance = Distance::query()->where('from_place_id', $fromId)->where('to_place_id', $toId)->first()
            ?? Distance::query()->where('from_place_id', $toId)->where('to_place_id', $fromId)->first();

        return $distance === null ? null : (string) $distance->km;
    }

    private function nextNumber(string $organizationId): int
    {
        // Serialise numbering on the organisation's settings row (Postgres
        // does not allow FOR UPDATE with an aggregate).
        Setting::withoutGlobalScopes()->where('organization_id', $organizationId)->lockForUpdate()->first();
        $max = Claim::withoutGlobalScopes()->where('organization_id', $organizationId)->max('number');

        return ((int) $max) + 1;
    }

    /**
     * Reload claims of one organisation with row locks (in id order, against
     * deadlocks) and check that each has the status.
     *
     * @param  Claim|iterable<Claim>  $claims
     * @return Collection<int, Claim>
     */
    public function lockMany(Claim|iterable $claims, string $status): Collection
    {
        $ids = collect($claims instanceof Claim ? [$claims] : $claims)->map(fn (Claim $c): string => (string) $c->id)->unique()->values();
        if ($ids->isEmpty()) {
            throw new \DomainException(__('expense-claims::ec.nothing_selected'));
        }
        $locked = Claim::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($locked->count() !== $ids->count() || $locked->pluck('organization_id')->unique()->count() !== 1) {
            throw new \DomainException(__('expense-claims::ec.nothing_selected'));
        }
        $locked->each(fn (Claim $c) => $this->assertStatus($c, $status));

        return $locked->sortBy([['date', 'asc'], ['number', 'asc']])->values();
    }

    /**
     * Reload the claim with a row lock and check its status, so concurrent
     * requests (double click, payroll posting) cannot act on a stale state.
     */
    private function locked(Claim $claim, string $status): Claim
    {
        $fresh = Claim::withoutGlobalScopes()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
        $this->assertStatus($fresh, $status);

        return $fresh;
    }

    private function assertStatus(Claim $claim, string $status): void
    {
        if ($claim->status !== $status) {
            throw new \DomainException(__('expense-claims::ec.wrong_status', ['status' => __('expense-claims::ec.status_'.$claim->status)]));
        }
    }
}
