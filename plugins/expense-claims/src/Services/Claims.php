<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\ClaimLine;
use Plugins\ExpenseClaims\Models\Distance;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Setting;

/**
 * Claim life cycle: draft → approved (booked: Dr expense · Cr liability) →
 * settled (payroll or bank) or converted into a debt record.
 */
final class Claims
{
    public function __construct(
        private LedgerService $ledger,
        private Accounts $accounts,
        private Rates $rates,
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

    public function approve(Claim $claim): Claim
    {
        return DB::transaction(function () use ($claim): Claim {
            $claim = $this->locked($claim, Claim::STATUS_DRAFT)->load(['lines', 'person']);
            if ($claim->lines->isEmpty() || ! Money::isPositive((string) $claim->total)) {
                throw new \DomainException(__('expense-claims::ec.claim_empty'));
            }

            $orgId = (string) $claim->organization_id;
            $settings = Setting::forOrganization($orgId);
            $liability = $claim->person->is_owner ? $settings->owner_liability_code : $settings->staff_liability_code;

            $lines = $claim->lines
                ->groupBy('expense_account_code')
                ->map(fn ($group, $code): JournalLineData => new JournalLineData(
                    accountId: $this->accounts->id($orgId, (string) $code),
                    debit: Money::sumAmounts($group->map(fn (ClaimLine $l): array => ['amount' => (string) $l->amount])->all()),
                    credit: '0',
                    description: "{$claim->reference()} {$claim->title}",
                ))
                ->values()
                ->all();
            $lines[] = new JournalLineData(
                accountId: $this->accounts->id($orgId, $liability),
                debit: '0',
                credit: (string) $claim->total,
                description: "{$claim->reference()} {$claim->person->name}",
            );

            $entry = $this->ledger->postEntry($orgId, new JournalEntryData(
                date: $claim->date->toDateString(),
                reference: $this->accounts->uniqueReference($orgId, $claim->reference()),
                description: "Note de frais {$claim->reference()} — {$claim->person->name}: {$claim->title}",
                lines: $lines,
            ));

            $claim->update([
                'status' => Claim::STATUS_APPROVED,
                'liability_account_code' => $liability,
                'journal_entry_id' => $entry->id,
            ]);

            return $claim;
        });
    }

    /** Reverse the booking and return an unpaid claim to draft. */
    public function unapprove(Claim $claim): Claim
    {
        return DB::transaction(function () use ($claim): Claim {
            $claim = $this->locked($claim, Claim::STATUS_APPROVED);
            $this->reverse($claim->journal_entry_id, "Annulation {$claim->reference()}");
            $claim->update(['status' => Claim::STATUS_DRAFT, 'liability_account_code' => null, 'journal_entry_id' => null]);

            return $claim;
        });
    }

    public function payByBank(Claim $claim, string $date): Claim
    {
        return DB::transaction(function () use ($claim, $date): Claim {
            $claim = $this->locked($claim, Claim::STATUS_APPROVED)->load('person');
            $orgId = (string) $claim->organization_id;
            $entry = $this->ledger->postEntry($orgId, new JournalEntryData(
                date: $date,
                reference: $this->accounts->uniqueReference($orgId, $claim->reference().'-PAY'),
                description: "Remboursement {$claim->reference()} — {$claim->person->name}",
                lines: [
                    new JournalLineData($this->accounts->id($orgId, (string) $claim->liability_account_code), (string) $claim->total, '0', $claim->reference()),
                    new JournalLineData($this->accounts->id($orgId, Setting::forOrganization($orgId)->bank_account_code), '0', (string) $claim->total, $claim->reference()),
                ],
            ));

            $claim->update(['status' => Claim::STATUS_SETTLED, 'settled_via' => 'bank', 'settled_on' => $date, 'settlement_entry_id' => $entry->id]);

            return $claim;
        });
    }

    public function cancelBankPayment(Claim $claim): Claim
    {
        return DB::transaction(function () use ($claim): Claim {
            $claim = $this->locked($claim, Claim::STATUS_SETTLED);
            if ($claim->settled_via !== 'bank') {
                throw new \DomainException(__('expense-claims::ec.not_paid_by_bank'));
            }
            $this->reverse($claim->settlement_entry_id, "Annulation remboursement {$claim->reference()}");
            $claim->update(['status' => Claim::STATUS_APPROVED, 'settled_via' => null, 'settled_on' => null, 'settlement_entry_id' => null]);

            return $claim;
        });
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

    public function reverse(?string $journalEntryId, string $description): void
    {
        if ($journalEntryId === null) {
            return;
        }
        $entry = JournalEntry::withoutGlobalScopes()->findOrFail($journalEntryId);
        $this->ledger->postDraft($this->ledger->reverseEntry($entry, $description));
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
