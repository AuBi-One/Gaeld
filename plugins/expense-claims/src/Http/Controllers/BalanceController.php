<?php

namespace Plugins\ExpenseClaims\Http\Controllers;

use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\DebtRepayment;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Setting;
use Plugins\ExpenseClaims\Services\Claims;
use Plugins\ExpenseClaims\Services\Debts;

/**
 * Expense balances (managers only, `expenses.approve`, §8.2): everyone's
 * unpaid claims with filters and grouped actions (approve, pay from an
 * account, pass to debt), balances per person and the debt records.
 */
class BalanceController extends PluginController
{
    /** Most claims listed at once; narrow the filters beyond that. */
    private const LIMIT = 500;

    public function __construct(private Claims $claims, private Debts $debts) {}

    public function index(Request $request): Response
    {
        $this->authorizeWrite(); // managers only (expenses.approve), not the viewer role (D39)
        $status = in_array($request->string('status')->toString(), [Claim::STATUS_DRAFT, Claim::STATUS_APPROVED], true) ? $request->string('status')->toString() : '';
        $personId = $request->string('person_id')->toString();
        $date = fn (string $key): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->string($key)->toString()) === 1 ? $request->string($key)->toString() : '';
        $from = $date('from');
        $to = $date('to');

        $unpaid = Claim::query()->whereIn('status', [Claim::STATUS_DRAFT, Claim::STATUS_APPROVED])->get(['id', 'person_id', 'status', 'total']);
        $debts = DebtRecord::query()->with(['person:id,name', 'repayments'])->orderByDesc('date')->get();

        $claims = Claim::query()
            ->with('person:id,name')
            ->whereIn('status', $status !== '' ? [$status] : [Claim::STATUS_DRAFT, Claim::STATUS_APPROVED])
            ->when($personId !== '', fn ($q) => $q->where('person_id', $personId))
            ->when($from !== '', fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to !== '', fn ($q) => $q->whereDate('date', '<=', $to))
            ->orderBy('date')
            ->orderBy('number')
            ->limit(self::LIMIT + 1)
            ->get();

        $people = Person::query()->orderBy('name')->get()->map(fn (Person $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'is_owner' => $p->is_owner,
            'draft_total' => $this->sum($unpaid->where('person_id', $p->id)->where('status', Claim::STATUS_DRAFT)->pluck('total')),
            'approved_total' => $this->sum($unpaid->where('person_id', $p->id)->where('status', Claim::STATUS_APPROVED)->pluck('total')),
            'debt_total' => $this->sum($debts->where('person_id', $p->id)->map(fn (DebtRecord $d): string => $d->remaining())),
        ])->values();

        return $this->page('ExpenseClaims/Balances', [
            'filters' => ['status' => $status, 'person_id' => $personId, 'from' => $from, 'to' => $to],
            'claims' => $claims->take(self::LIMIT)->map(fn (Claim $c): array => [
                'id' => $c->id,
                'reference' => $c->reference(),
                'date' => $c->date->toDateString(),
                'person' => $c->person?->name,
                'title' => $c->title,
                'status' => $c->status,
                'total' => (string) $c->total,
            ])->values(),
            'truncated' => $claims->count() > self::LIMIT,
            'people' => $people,
            'accounts' => $this->paymentAccounts(),
            'defaultAccount' => Setting::forOrganization($this->orgId())->bank_account_code,
            'defaultDebtDate' => $to !== '' ? $to : now()->subYear()->endOfYear()->toDateString(),
            'debts' => $debts->map(fn (DebtRecord $d): array => [
                'id' => $d->id,
                'person' => $d->person?->name,
                'date' => $d->date->toDateString(),
                'amount' => (string) $d->amount,
                'remaining' => $d->remaining(),
                'account_code' => $d->account_code,
                'entry_lost' => $d->entry_expected && $d->journal_entry_id === null, // its entry was deleted in the journal
                'notes' => $d->notes,
                'repayments' => $d->repayments->map(fn (DebtRepayment $r): array => [
                    'date' => $r->date->toDateString(),
                    'amount' => (string) $r->amount,
                    'via' => $r->via,
                ])->values(),
            ])->values(),
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $approved = $this->claims->approve($this->selected($request), $request->user()?->id);

        return back()->with('success', trans_choice('expense-claims::ec.approved_count', $approved->count(), ['count' => $approved->count()]));
    }

    public function pay(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'account_code' => ['required', 'string', Rule::in(array_column($this->paymentAccounts(), 'code'))],
        ]);
        $paid = $this->claims->pay($this->selected($request), $data['date'], $data['account_code']);

        return back()->with('success', trans_choice('expense-claims::ec.paid_count', $paid->count(), ['count' => $paid->count()]));
    }

    public function debt(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $records = $this->debts->convert($this->selected($request), $data['date'], $data['notes'] ?? null);

        return back()->with('success', trans_choice('expense-claims::ec.converted_count', $records->count(), ['count' => $records->count()]));
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
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'account_code' => ['nullable', 'string', Rule::in(array_column($this->paymentAccounts(), 'code'))],
        ]);
        $this->debts->repay($debt, $data['date'], (string) $data['amount'], $data['account_code'] ?? null);

        return back()->with('success', __('expense-claims::ec.repaid'));
    }

    /**
     * The selected claims of the current organisation.
     *
     * @return EloquentCollection<int, Claim>
     */
    private function selected(Request $request): EloquentCollection
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:'.self::LIMIT], 'ids.*' => ['required', 'uuid']])['ids'];
        $claims = Claim::query()->whereIn('id', $ids)->get();
        abort_if($claims->count() !== count(array_unique($ids)), 404);

        return $claims;
    }

    /**
     * @param  Collection<array-key, mixed>  $amounts
     */
    private function sum(Collection $amounts): string
    {
        return Money::sumAmounts($amounts->map(fn ($a): array => ['amount' => (string) $a])->values()->all());
    }
}
