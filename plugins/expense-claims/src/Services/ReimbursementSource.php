<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Domains\Payroll\Models\SalarySlip;
use App\Support\Money;
use Carbon\Carbon;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\DebtRepayment;
use Plugins\ExpenseClaims\Models\Person;

/**
 * Offers a person's approved, unpaid claims (whole claims only) and the
 * remaining balance of their debt records to the payroll run.
 * Item ids: "claim:<uuid>" and "debt:<uuid>".
 */
final class ReimbursementSource implements ReimbursementSourceInterface
{
    public function openItems(string $organizationId, string $employeeId): array
    {
        $person = $this->person($organizationId, $employeeId);
        if ($person === null) {
            return [];
        }

        return array_map(
            fn (array $item): array => ['id' => $item['id'], 'date' => $item['date'], 'label' => $item['label'], 'amount' => $item['amount']],
            $this->items($person),
        );
    }

    public function resolve(string $organizationId, string $employeeId, array $itemIds): array
    {
        $person = $this->person($organizationId, $employeeId);
        $open = $person === null ? [] : collect($this->items($person))->keyBy('id');

        return array_map(function (string $id) use ($open): array {
            $item = $open[$id] ?? null;
            if ($item === null) {
                throw new \DomainException(__('expense-claims::ec.item_not_open', ['id' => $id]));
            }

            return $item;
        }, $itemIds);
    }

    public function settle(SalarySlip $slip, array $items): void
    {
        $orgId = (string) $slip->organization_id;
        $person = $this->person($orgId, (string) $slip->employee_id);
        $paidOn = Carbon::create($slip->period_year, $slip->period_month)->endOfMonth()->toDateString();

        foreach ($items as $item) {
            [$kind, $id] = explode(':', $item['id'], 2) + [1 => ''];
            if ($kind === 'claim') {
                // Conditional update: only an approved claim of this person with the booked total.
                $updated = Claim::withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->where('person_id', $person?->id)
                    ->where('status', Claim::STATUS_APPROVED)
                    ->where('total', $item['amount'])
                    ->whereKey($id)
                    ->update([
                        'status' => Claim::STATUS_SETTLED,
                        'settled_via' => 'payroll',
                        'settled_on' => $paidOn,
                        'salary_slip_id' => $slip->id,
                    ]);
                if ($updated !== 1) {
                    throw new \DomainException(__('expense-claims::ec.item_not_open', ['id' => $item['id']]));
                }

                continue;
            }

            $debt = DebtRecord::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('person_id', $person?->id)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();
            if ($debt === null || Money::compare($item['amount'], $debt->load('repayments')->remaining()) > 0) {
                throw new \DomainException(__('expense-claims::ec.item_not_open', ['id' => $item['id']]));
            }
            $debt->repayments()->create(['date' => $paidOn, 'amount' => $item['amount'], 'via' => 'payroll', 'salary_slip_id' => $slip->id]);
        }
    }

    public function release(SalarySlip $slip): void
    {
        Claim::withoutGlobalScopes()
            ->where('salary_slip_id', $slip->id)
            ->where('settled_via', 'payroll')
            ->update(['status' => Claim::STATUS_APPROVED, 'settled_via' => null, 'settled_on' => null, 'salary_slip_id' => null]);
        DebtRepayment::query()->where('salary_slip_id', $slip->id)->delete();
    }

    private function person(string $organizationId, string $employeeId): ?Person
    {
        return Person::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->first();
    }

    /**
     * @return list<array{id: string, date: string, label: string, amount: string, account_code: string}>
     */
    private function items(Person $person): array
    {
        $claims = Claim::withoutGlobalScopes()
            ->where('organization_id', $person->organization_id)
            ->where('person_id', $person->id)
            ->where('status', Claim::STATUS_APPROVED)
            ->orderBy('date')
            ->get()
            ->map(fn (Claim $c): array => [
                'id' => 'claim:'.$c->id,
                'date' => $c->date->toDateString(),
                'label' => $c->reference().' '.$c->title,
                'amount' => Money::normalize((string) $c->total),
                'account_code' => (string) $c->liability_account_code,
            ]);

        $debts = DebtRecord::withoutGlobalScopes()
            ->where('organization_id', $person->organization_id)
            ->where('person_id', $person->id)
            ->with('repayments')
            ->orderBy('date')
            ->get()
            ->filter(fn (DebtRecord $d): bool => Money::isPositive($d->remaining()))
            ->map(fn (DebtRecord $d): array => [
                'id' => 'debt:'.$d->id,
                'date' => $d->date->toDateString(),
                'label' => (string) __('expense-claims::ec.debt_item_label', ['date' => $d->date->format('d.m.Y')]),
                'amount' => $d->remaining(),
                'account_code' => $d->account_code,
            ]);

        return array_values($claims->concat($debts)->all());
    }
}
