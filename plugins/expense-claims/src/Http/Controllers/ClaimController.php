<?php

namespace Plugins\ExpenseClaims\Http\Controllers;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Organizations\Enums\Permission;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\ClaimLine;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Place;
use Plugins\ExpenseClaims\Models\Setting;
use Plugins\ExpenseClaims\Models\VehicleRate;
use Plugins\ExpenseClaims\Services\Claims;
use Plugins\ExpenseClaims\Services\Distances;
use Plugins\ExpenseClaims\Services\Rates;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClaimController extends PluginController
{
    public function __construct(private Claims $claims, private Distances $distances) {}

    /** The user's own claims and balances (§8.2). */
    public function index(Request $request): Response
    {
        abort_unless($this->allows(Permission::ExpensesCreate) || $this->allows(Permission::ExpensesViewOwn) || $this->allows(Permission::ExpensesView), 403);
        $status = $request->string('status')->toString();
        $personId = $this->ownPersonId();

        // No person linked to the account: an empty list (nil UUID matches nothing).
        $key = $personId ?? '00000000-0000-0000-0000-000000000000';
        $own = Claim::query()->where('person_id', $key);
        $claims = (clone $own)
            ->with('debtRecord.repayments')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('date')
            ->orderByDesc('number')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Claim $c): array => $this->row($c));

        $byStatus = (clone $own)->selectRaw('status, count(*) as n, coalesce(sum(total), 0) as total')->groupBy('status')->get()->keyBy('status');
        $tile = fn (string $s): array => ['count' => (int) ($byStatus[$s]->n ?? 0), 'total' => Money::normalize((string) ($byStatus[$s]->total ?? '0'))];
        $debts = DebtRecord::query()->where('person_id', $key)->with('repayments')->get();

        return $this->page('ExpenseClaims/Index', [
            'claims' => $claims,
            'filters' => ['status' => $status],
            'hasPerson' => $personId !== null,
            'balances' => [
                'draft' => $tile(Claim::STATUS_DRAFT),
                'approved' => $tile(Claim::STATUS_APPROVED),
                'debt' => [
                    'count' => $debts->filter(fn (DebtRecord $d): bool => Money::isPositive($d->remaining()))->count(),
                    'total' => Money::sumAmounts($debts->map(fn (DebtRecord $d): array => ['amount' => $d->remaining()])->values()->all()),
                ],
            ],
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        $this->authorizeCreate();
        if ($this->ownPersonId() === null && ! $this->canManage()) {
            return redirect('/expense-claims')->with('error', __('expense-claims::ec.no_person'));
        }

        return $this->page('ExpenseClaims/Form', $this->formProps(null));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeCreate();
        $claim = $this->claims->saveDraft($this->orgId(), $this->validated($request));

        return $this->backToList($claim)->with('success', __('expense-claims::ec.saved'));
    }

    public function show(Request $request, Claim $claim): Response
    {
        $this->authorizeSee($claim);
        $claim->load(['person', 'lines.fromPlace', 'lines.toPlace', 'approver:id,name', 'debtRecord.repayments']);

        return $this->page('ExpenseClaims/Show', [
            'claim' => $this->present($claim),
            'from' => $request->string('from')->toString() === 'balances' && $this->allows(Permission::ExpensesView) ? 'balances' : 'claims',
            'canEdit' => $claim->isDraft() && $this->mayEdit($claim),
            'canAttach' => $this->mayAttach($claim),
            'accounts' => $this->canManage() ? $this->paymentAccounts() : [],
            'defaultAccount' => Setting::forOrganization($this->orgId())->bank_account_code,
            'entries' => JournalEntry::query()
                ->whereIn('id', array_filter([$claim->journal_entry_id, $claim->settlement_entry_id, $claim->debtRecord?->journal_entry_id]))
                ->get(['id', 'reference', 'date', 'is_posted']),
        ]);
    }

    public function edit(Claim $claim): Response|RedirectResponse
    {
        abort_unless($this->mayEdit($claim), 403);
        if (! $claim->isDraft()) {
            return redirect("/expense-claims/{$claim->id}")->with('error', __('expense-claims::ec.only_draft_editable'));
        }

        return $this->page('ExpenseClaims/Form', $this->formProps($claim->load('lines')));
    }

    public function update(Request $request, Claim $claim): RedirectResponse
    {
        abort_unless($this->mayEdit($claim), 403);
        $claim = $this->claims->saveDraft($this->orgId(), $this->validated($request), $claim);

        return $this->backToList($claim)->with('success', __('expense-claims::ec.saved'));
    }

    public function destroy(Claim $claim): RedirectResponse
    {
        abort_unless($this->mayEdit($claim), 403);
        $own = $this->isOwn($claim);
        foreach ($this->claims->delete($claim) as $file) {
            Storage::disk('local')->delete($file['path']);
        }

        return redirect($own ? '/expense-claims' : '/expense-balances')->with('success', __('expense-claims::ec.deleted'));
    }

    public function approve(Request $request, Claim $claim): RedirectResponse
    {
        $this->authorizeWrite();
        $this->claims->approve($claim, $request->user()?->id);

        return back()->with('success', __('expense-claims::ec.approved'));
    }

    public function unapprove(Claim $claim): RedirectResponse
    {
        $this->authorizeWrite();
        $this->claims->unapprove($claim);

        return back()->with('success', __('expense-claims::ec.unapproved'));
    }

    public function payBank(Request $request, Claim $claim): RedirectResponse
    {
        $this->authorizeWrite();
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'account_code' => ['nullable', 'string', Rule::in(array_column($this->paymentAccounts(), 'code'))],
        ]);
        $this->claims->pay($claim, $data['date'], $data['account_code'] ?? null);

        return back()->with('success', __('expense-claims::ec.paid'));
    }

    public function cancelBank(Claim $claim): RedirectResponse
    {
        $this->authorizeWrite();
        $count = $this->claims->cancelPayment($claim);

        return back()->with('success', trans_choice('expense-claims::ec.payment_cancelled', $count, ['count' => $count]));
    }

    public function distance(Request $request): JsonResponse
    {
        $this->authorizeCreate();
        $data = $request->validate(['from' => ['required', 'uuid'], 'to' => ['required', 'uuid', 'different:from']]);
        $from = Place::query()->whereKey($data['from'])->firstOrFail();
        $to = Place::query()->whereKey($data['to'])->firstOrFail();

        try {
            return response()->json(['km' => $this->distances->km($from, $to)]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function attach(Request $request, Claim $claim): RedirectResponse
    {
        abort_unless($this->mayAttach($claim), 403);
        $file = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,heic']])['file'];
        $path = $file->store("expense-claims/{$claim->organization_id}/{$claim->id}", 'local');
        $claim->update(['attachments' => [...($claim->attachments ?? []), ['path' => $path, 'name' => $file->getClientOriginalName()]]]);

        return back()->with('success', __('expense-claims::ec.attached'));
    }

    public function attachment(Claim $claim, int $index): StreamedResponse
    {
        $this->authorizeSee($claim);
        $file = ($claim->attachments ?? [])[$index] ?? abort(404);

        return Storage::disk('local')->download($file['path'], $file['name']);
    }

    /**
     * @return array{person_id: string, date: string, title: string, notes?: ?string, lines: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
        if (! $this->canManage()) {
            // Without `expenses.approve`, a claim is always the user's own.
            abort_if($this->ownPersonId() === null, 403);
            $request->merge(['person_id' => $this->ownPersonId()]);
        }
        /** @var array{person_id: string, date: string, title: string, notes?: ?string, lines: list<array<string, mixed>>} $data */
        $data = $request->validate([
            'person_id' => ['required', 'uuid', Rule::exists('ec_people', 'id')->where('organization_id', $this->orgId())],
            'date' => ['required', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.type' => ['required', Rule::in(ClaimLine::TYPES)],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['exclude_if:lines.*.type,km', 'required', 'numeric', 'decimal:0,2', 'gt:0'],
            'lines.*.km' => ['exclude_unless:lines.*.type,km', 'required', 'numeric', 'gt:0', 'max:5000'],
            'lines.*.from_place_id' => ['nullable', 'uuid', Rule::exists('ec_places', 'id')->where('organization_id', $this->orgId())],
            'lines.*.to_place_id' => ['nullable', 'uuid', Rule::exists('ec_places', 'id')->where('organization_id', $this->orgId())],
            'lines.*.round_trip' => ['boolean'],
            'lines.*.vehicle_type' => ['nullable', 'string', 'max:20'],
            'lines.*.km_override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(?Claim $claim): array
    {
        $own = $this->ownPersonId();
        $manage = $this->canManage();
        $people = Person::query()->orderBy('name')->when(! $manage, fn ($q) => $q->whereKey($own))->get(['id', 'name', 'home_place_id']);
        $ownHome = $people->firstWhere('id', $own)?->home_place_id;

        return [
            'claim' => $claim ? $this->present($claim) : null,
            'people' => $people,
            'defaultPersonId' => $own,
            'canChoosePerson' => $manage,
            // Other people's homes are only shown to managers.
            'places' => Place::query()->ordered()
                ->when(! $manage, fn ($q) => $q->where(fn ($q) => $q->where('kind', '!=', 'home')->orWhere('id', $ownHome)))
                ->get(['id', 'kind', 'label', 'city', 'lat', 'lon']),
            'rates' => $this->rates()->get(['vehicle_type', 'valid_from', 'valid_to', 'rate_per_km']),
            'routingEnabled' => $this->distances->isConfigured(),
        ];
    }

    /** The claim's person may change a draft (and add receipts); managers any claim. */
    private function mayEdit(Claim $claim): bool
    {
        return $this->canManage() || ($this->isOwn($claim) && $this->allows(Permission::ExpensesCreate));
    }

    /** Receipts: managers on any claim, the claim's person on their drafts. */
    private function mayAttach(Claim $claim): bool
    {
        return $this->canManage() || ($claim->isDraft() && $this->mayEdit($claim));
    }

    private function backToList(Claim $claim): RedirectResponse
    {
        return redirect($this->isOwn($claim) ? '/expense-claims' : '/expense-balances');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Claim $c): array
    {
        return [
            'id' => $c->id,
            'reference' => $c->reference(),
            'date' => $c->date->toDateString(),
            'title' => $c->title,
            'status' => $c->status,
            'settled_via' => $c->settled_via,
            'debt_repaid' => $c->debtRecord !== null && ! Money::isPositive($c->debtRecord->remaining()),
            'total' => (string) $c->total,
        ];
    }

    /**
     * @return Builder<VehicleRate>
     */
    private function rates(): Builder
    {
        app(Rates::class)->ensureDefaults($this->orgId());

        return VehicleRate::query()->orderBy('valid_from');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Claim $claim): array
    {
        return [
            'id' => $claim->id,
            'reference' => $claim->reference(),
            'person_id' => $claim->person_id,
            'person' => $claim->person?->name,
            'date' => $claim->date->toDateString(),
            'title' => $claim->title,
            'notes' => $claim->notes,
            'status' => $claim->status,
            'total' => (string) $claim->total,
            'liability_account_code' => $claim->liability_account_code,
            'settled_via' => $claim->settled_via,
            'settled_on' => $claim->settled_on?->toDateString(),
            'salary_slip_id' => $claim->salary_slip_id,
            'approved_at' => $claim->approved_at?->toIso8601String(),
            'approved_by' => $claim->approver?->name,
            'debt' => $claim->debtRecord === null ? null : [
                'date' => $claim->debtRecord->date->toDateString(),
                'remaining' => $claim->debtRecord->remaining(),
            ],
            'source' => $claim->source,
            'attachments' => collect($claim->attachments ?? [])->map(fn (array $f, int $i): array => ['index' => $i, 'name' => $f['name']])->values(),
            'lines' => $claim->lines->map(fn (ClaimLine $l): array => [
                'type' => $l->type,
                'description' => $l->description,
                'from_place_id' => $l->from_place_id,
                'to_place_id' => $l->to_place_id,
                'from' => $l->fromPlace?->label,
                'to' => $l->toPlace?->label,
                'round_trip' => $l->round_trip,
                'km_lookup' => $l->km_lookup,
                'km' => $l->km,
                'km_source' => $l->km_source,
                'km_override_reason' => $l->km_override_reason,
                'vehicle_type' => $l->vehicle_type,
                'rate' => $l->rate,
                'amount' => (string) $l->amount,
                'expense_account_code' => $l->expense_account_code,
            ])->values(),
        ];
    }
}
