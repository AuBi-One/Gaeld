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
 * Claim life cycle: draft → approved (status only) → paid (with a salary
 * or from an account) or moved into a debt record; the cost is booked at
 * payment or debt, one entry per person and batch
 * (docs/DESIGN-expense-claims.md §8.4, D37).
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
     * Approve drafts: status, approver and time only; nothing is booked (D37).
     * The cost is booked when the claim leaves the company: paid (from an
     * account or with a salary) or passed to debt.
     *
     * @param  Claim|iterable<Claim>  $claims
     * @return Collection<int, Claim>
     */
    public function approve(Claim|iterable $claims, ?int $approverId = null): Collection
    {
        return DB::transaction(function () use ($claims, $approverId): Collection {
            $locked = $this->lockMany($claims, Claim::STATUS_DRAFT)->load('lines');
            foreach ($locked as $claim) {
                if ($claim->lines->isEmpty() || ! Money::isPositive((string) $claim->total)) {
                    throw new \DomainException(__('expense-claims::ec.claim_empty').' ('.$claim->reference().')');
                }
            }
            Claim::withoutGlobalScopes()->whereIn('id', $locked->modelKeys())->update([
                'status' => Claim::STATUS_APPROVED,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Return an approved, unpaid claim to draft. Nothing to undo in the
     * ledger, except for a claim approved before D37 with its own approval
     * entry (legacy: undone like before). A claim whose cost was booked
     * before Gäld (migration without --book) cannot go back to draft.
     */
    public function unapprove(Claim $claim): Claim
    {
        return DB::transaction(function () use ($claim): Claim {
            // Lock every claim of the same (legacy) approval entry in id order first (no deadlock between two cancels).
            $entryId = Claim::withoutGlobalScopes()->whereKey($claim->id)->value('journal_entry_id');
            if ($entryId !== null) {
                Claim::withoutGlobalScopes()->where('journal_entry_id', $entryId)->orderBy('id')->lockForUpdate()->pluck('id');
            }
            $claim = $this->locked($claim, Claim::STATUS_APPROVED)->load(['lines', 'person']);
            if ($claim->journal_entry_id === null && $claim->liability_account_code !== null) {
                throw new \DomainException(__('expense-claims::ec.booked_before_gald'));
            }
            if ($claim->journal_entry_id !== null) {
                $this->undoLegacyApproval($claim);
            }
            $claim->forceFill(['status' => Claim::STATUS_DRAFT, 'liability_account_code' => null, 'journal_entry_id' => null, 'approved_by' => null, 'approved_at' => null])->save();

            return $claim;
        });
    }

    /**
     * Pay approved claims from a bank or cash account: one entry per person
     * for the batch, dated $date: Dr the cost (expense account per line, or
     * the liability for a claim booked earlier) · Cr the account.
     *
     * @param  Claim|iterable<Claim>  $claims
     * @return Collection<int, Claim>
     */
    public function pay(Claim|iterable $claims, string $date, ?string $accountCode = null): Collection
    {
        return DB::transaction(function () use ($claims, $date, $accountCode): Collection {
            $locked = $this->lockMany($claims, Claim::STATUS_APPROVED)->load(['lines', 'person']);
            if ($locked->contains(fn (Claim $c): bool => $c->date->toDateString() > $date)) {
                throw new \DomainException(__('expense-claims::ec.claim_after_payment_date', ['date' => $date]));
            }
            $orgId = (string) $locked->first()?->organization_id;
            $accountCode ??= Setting::forOrganization($orgId)->bank_account_code;

            foreach ($locked->groupBy('person_id') as $group) {
                $person = $group->first()?->person;
                $total = Money::sumAmounts($group->map(fn (Claim $c): array => ['amount' => (string) $c->total])->values()->all());
                $entry = $this->journal->book($orgId, new JournalEntryData(
                    date: $date,
                    reference: $this->accounts->uniqueReference($orgId, 'EC-PAY-'.str_replace('-', '', $date).'-'.EntryLines::tag((string) $person?->name)),
                    description: "Remboursement notes de frais — {$person?->name} ({$group->count()})",
                    lines: [
                        ...$this->lines->costs($orgId, $group, 'debit'),
                        new JournalLineData($this->accounts->id($orgId, $accountCode), '0', $total, $this->lines->references($group)),
                    ],
                ));
                Claim::withoutGlobalScopes()->whereIn('id', $group->modelKeys())->update([
                    'status' => Claim::STATUS_SETTLED, 'settled_via' => 'bank', 'settled_on' => $date, 'settlement_entry_id' => $entry->id,
                ]);
            }

            return $locked;
        });
    }

    /**
     * Cancel a payment from an account: the person's payment entry is undone
     * and every claim it paid is approved (unpaid) again.
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
     * Undo the approval entry of a claim approved before D37 (Dr expense ·
     * Cr liability, possibly grouped with other claims): a draft entry is
     * rewritten without it, a posted one gets a counter-entry for its part.
     */
    private function undoLegacyApproval(Claim $claim): void
    {
        $orgId = (string) $claim->organization_id;
        $lines = fn (Collection $claims): array => $this->legacyApprovalLines($orgId, $claims);
        $draft = $this->journal->draft($claim->journal_entry_id);
        if ($draft !== null) {
            $others = Claim::withoutGlobalScopes()->where('journal_entry_id', $draft->id)->whereKeyNot($claim->id)
                ->orderBy('id')->lockForUpdate()->get()->load(['lines', 'person']);
            $newId = $this->journal->replaceDraft($draft, $lines($others));
            Claim::withoutGlobalScopes()->whereIn('id', $others->modelKeys())->update(['journal_entry_id' => $newId]);

            return;
        }
        $this->journal->undoPart($claim->journal_entry_id, "Annulation approbation {$claim->reference()}", $lines(new Collection([$claim])));
    }

    /**
     * @param  Collection<int, Claim>  $claims  with lines and person loaded
     * @return list<JournalLineData>
     */
    private function legacyApprovalLines(string $orgId, Collection $claims): array
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
