<?php

namespace Plugins\ExpenseClaims\Http\Controllers;

use App\Domains\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\ClaimLine;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Place;
use Plugins\ExpenseClaims\Models\VehicleRate;
use Plugins\ExpenseClaims\Services\Claims;
use Plugins\ExpenseClaims\Services\Distances;
use Plugins\ExpenseClaims\Services\Rates;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClaimController extends PluginController
{
    public function __construct(private Claims $claims, private Distances $distances) {}

    public function index(Request $request): Response
    {
        $this->authorizeView();
        $status = $request->string('status')->toString();
        $personId = $request->string('person_id')->toString();

        $claims = Claim::query()
            ->with('person:id,name')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($personId !== '', fn ($q) => $q->where('person_id', $personId))
            ->orderByDesc('date')
            ->orderByDesc('number')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Claim $c): array => [
                'id' => $c->id,
                'reference' => $c->reference(),
                'date' => $c->date->toDateString(),
                'person' => $c->person?->name,
                'title' => $c->title,
                'status' => $c->status,
                'settled_via' => $c->settled_via,
                'total' => (string) $c->total,
            ]);

        $open = Claim::query()->where('status', Claim::STATUS_APPROVED);

        return $this->page('ExpenseClaims/Index', [
            'claims' => $claims,
            'filters' => ['status' => $status, 'person_id' => $personId],
            'people' => Person::query()->orderBy('name')->get(['id', 'name']),
            'openTotal' => (string) $open->sum('total'),
            'openCount' => $open->count(),
        ]);
    }

    public function create(): Response
    {
        $this->authorizeWrite();

        return $this->page('ExpenseClaims/Form', $this->formProps(null));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $claim = $this->claims->saveDraft($this->orgId(), $this->validated($request));

        return redirect("/payroll/expense-claims/{$claim->id}")->with('success', __('expense-claims::ec.saved'));
    }

    public function show(Claim $claim): Response
    {
        $this->authorizeView();
        $claim->load(['person', 'lines.fromPlace', 'lines.toPlace']);

        return $this->page('ExpenseClaims/Show', [
            'claim' => $this->present($claim),
            'entries' => JournalEntry::query()
                ->whereIn('id', array_filter([$claim->journal_entry_id, $claim->settlement_entry_id]))
                ->get(['id', 'reference', 'date']),
        ]);
    }

    public function edit(Claim $claim): Response|RedirectResponse
    {
        $this->authorizeWrite();
        if (! $claim->isDraft()) {
            return redirect("/payroll/expense-claims/{$claim->id}")->with('error', __('expense-claims::ec.only_draft_editable'));
        }

        return $this->page('ExpenseClaims/Form', $this->formProps($claim->load('lines')));
    }

    public function update(Request $request, Claim $claim): RedirectResponse
    {
        $this->authorizeWrite();
        $this->claims->saveDraft($this->orgId(), $this->validated($request), $claim);

        return redirect("/payroll/expense-claims/{$claim->id}")->with('success', __('expense-claims::ec.saved'));
    }

    public function destroy(Claim $claim): RedirectResponse
    {
        $this->authorizeWrite();
        foreach ($this->claims->delete($claim) as $file) {
            Storage::disk('local')->delete($file['path']);
        }

        return redirect('/payroll/expense-claims')->with('success', __('expense-claims::ec.deleted'));
    }

    public function approve(Claim $claim): RedirectResponse
    {
        $this->authorizeWrite();
        $this->claims->approve($claim);

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
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $this->claims->payByBank($claim, $date);

        return back()->with('success', __('expense-claims::ec.paid'));
    }

    public function cancelBank(Claim $claim): RedirectResponse
    {
        $this->authorizeWrite();
        $this->claims->cancelBankPayment($claim);

        return back()->with('success', __('expense-claims::ec.payment_cancelled'));
    }

    public function distance(Request $request): JsonResponse
    {
        $this->authorizeWrite();
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
        $this->authorizeWrite();
        $file = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,heic']])['file'];
        $path = $file->store("expense-claims/{$claim->organization_id}/{$claim->id}", 'local');
        $claim->update(['attachments' => [...($claim->attachments ?? []), ['path' => $path, 'name' => $file->getClientOriginalName()]]]);

        return back()->with('success', __('expense-claims::ec.attached'));
    }

    public function attachment(Claim $claim, int $index): StreamedResponse
    {
        $this->authorizeView();
        $file = ($claim->attachments ?? [])[$index] ?? abort(404);

        return Storage::disk('local')->download($file['path'], $file['name']);
    }

    /**
     * @return array{person_id: string, date: string, title: string, notes?: ?string, lines: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
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
        return [
            'claim' => $claim ? $this->present($claim) : null,
            'people' => Person::query()->orderBy('name')->get(['id', 'name', 'home_place_id']),
            'places' => Place::query()->ordered()->get(['id', 'kind', 'label', 'city', 'lat', 'lon']),
            'rates' => $this->rates()->get(['vehicle_type', 'valid_from', 'valid_to', 'rate_per_km']),
            'routingEnabled' => $this->distances->isConfigured(),
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
