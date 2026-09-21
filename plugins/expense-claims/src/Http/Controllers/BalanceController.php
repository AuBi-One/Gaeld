<?php

namespace Plugins\ExpenseClaims\Http\Controllers;

use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Response;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\DebtRepayment;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Services\Debts;

class BalanceController extends PluginController
{
    public function __construct(private Debts $debts) {}

    public function index(Request $request): Response
    {
        $this->authorizeView();
        $date = $request->string('date')->toString();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : now()->subYear()->endOfYear()->toDateString();

        $open = Claim::query()->where('status', Claim::STATUS_APPROVED)->get(['person_id', 'date', 'total']);
        $debts = DebtRecord::query()->with(['person:id,name', 'repayments'])->orderByDesc('date')->get();

        $people = Person::query()->orderBy('name')->get()->map(fn (Person $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'is_owner' => $p->is_owner,
            'open_count' => $open->where('person_id', $p->id)->count(),
            'open_total' => $this->sum($open->where('person_id', $p->id)->pluck('total')),
            'convertible_total' => $this->sum($open->where('person_id', $p->id)->filter(fn (Claim $c): bool => $c->date->toDateString() <= $date)->pluck('total')),
            'debt_total' => $this->sum($debts->where('person_id', $p->id)->map(fn (DebtRecord $d): string => $d->remaining())),
        ])->values();

        return $this->page('ExpenseClaims/Balances', [
            'date' => $date,
            'people' => $people,
            'debts' => $debts->map(fn (DebtRecord $d): array => [
                'id' => $d->id,
                'person' => $d->person?->name,
                'date' => $d->date->toDateString(),
                'amount' => (string) $d->amount,
                'remaining' => $d->remaining(),
                'account_code' => $d->account_code,
                'notes' => $d->notes,
                'repayments' => $d->repayments->map(fn (DebtRepayment $r): array => [
                    'date' => $r->date->toDateString(),
                    'amount' => (string) $r->amount,
                    'via' => $r->via,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * @param  Collection<array-key, mixed>  $amounts
     */
    private function sum(Collection $amounts): string
    {
        return Money::sumAmounts($amounts->map(fn ($a): array => ['amount' => (string) $a])->values()->all());
    }

    public function convert(Request $request, Person $person): RedirectResponse
    {
        $this->authorizeWrite();
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $this->debts->convert($person, $data['date'], $data['notes'] ?? null);

        return back()->with('success', __('expense-claims::ec.converted'));
    }

    public function cancel(DebtRecord $debt): RedirectResponse
    {
        $this->authorizeWrite();
        $this->debts->cancel($debt);

        return back()->with('success', __('expense-claims::ec.debt_cancelled'));
    }

    public function repay(Request $request, DebtRecord $debt): RedirectResponse
    {
        $this->authorizeWrite();
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0']]);
        $this->debts->repayByBank($debt->load('repayments'), $data['date'], (string) $data['amount']);

        return back()->with('success', __('expense-claims::ec.repaid'));
    }
}
