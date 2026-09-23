<?php

namespace Plugins\Offers\Http\Controllers;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Organizations\Models\Organization;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Response;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Models\OfferInvoice;
use Plugins\Offers\Models\OfferInvoiceLine;
use Plugins\Offers\Models\OfferLine;
use Plugins\Offers\Models\OfferSetting;
use Plugins\Offers\Models\OfferTemplate;
use Plugins\Offers\Services\OfferPdf;
use Plugins\Offers\Services\Offers;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class OfferController extends PluginController
{
    /** Status filter values: the stored statuses plus "expired" (sent, past validity). */
    private const FILTERS = [...Offer::STATUSES, 'expired'];

    public function __construct(private Offers $offers, private OfferPdf $pdf) {}

    public function index(Request $request): Response
    {
        $this->authorizeView();
        $status = $request->string('status')->toString();
        $contactId = $request->string('contact')->toString();
        $year = $request->string('year')->toString();
        $today = now()->toDateString();

        $offers = Offer::query()
            ->with(['contact:id,uuid,name'])
            ->when(in_array($status, Offer::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($status === 'expired', fn ($q) => $q->where('status', Offer::STATUS_SENT)->where('valid_until', '<', $today))
            ->when(ctype_digit($contactId), fn ($q) => $q->where('contact_id', (int) $contactId))
            ->when(preg_match('/^\d{4}$/', $year) === 1, fn ($q) => $q->whereYear('offer_date', (int) $year))
            ->orderByDesc('offer_date')
            ->orderByDesc('number')
            ->paginate(25)
            ->withQueryString();
        $invoicing = $this->invoicing(Offer::query()->whereKey($offers->getCollection()->pluck('id')->all()));
        $offers->through(fn (Offer $o): array => [
            'id' => $o->id,
            'number' => $o->number,
            'offer_date' => $o->offer_date->toDateString(),
            'valid_until' => $o->valid_until?->toDateString(),
            'contact' => $o->contact->name ?? ($o->recipient['company'] ?? ''),
            'attention' => $o->recipient['attention'] ?? null,
            'title' => $o->title,
            'status' => $o->isExpired() ? 'expired' : $o->status,
            'total' => (string) $o->total,
            'currency' => $o->currency,
            'invoicing' => $this->invoicingState($invoicing->get($o->id)),
        ]);

        $open = Offer::query()->where('status', Offer::STATUS_SENT)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today));
        // Accepted offers with at least one line left to invoice (net remaining).
        $left = $this->invoicing(Offer::query()->where('status', Offer::STATUS_ACCEPTED))
            ->filter(fn (object $row): bool => (int) $row->open_lines > 0);

        return $this->page('Offers/Index', [
            'offers' => $offers,
            'filters' => ['status' => $status, 'contact' => $contactId, 'year' => $year],
            'statuses' => self::FILTERS,
            'contacts' => Contact::query()->whereIn('id', Offer::query()->select('contact_id'))->orderBy('name')->get(['id', 'name']),
            'years' => Offer::query()->selectRaw('DISTINCT EXTRACT(YEAR FROM offer_date)::int AS y')->orderByDesc('y')->pluck('y'),
            'stats' => [
                'open' => ['count' => (clone $open)->count(), 'total' => (string) (clone $open)->sum('total')],
                'to_invoice' => ['count' => $left->count(), 'total' => self::sum($left->map(fn (object $row): string => number_format((float) $row->remaining, 2, '.', ''))->all())],
                'drafts' => Offer::query()->where('status', Offer::STATUS_DRAFT)->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeWrite();
        $templateId = $request->string('template')->toString();
        $template = Str::isUuid($templateId)
            ? OfferTemplate::query()->find($templateId)
            : OfferTemplate::query()->where('is_default', true)->first();

        return $this->page('Offers/Form', $this->formProps(null) + [
            'initialTemplateId' => $template?->id,
            'initialContactId' => ctype_digit($request->string('contact')->toString()) ? (int) $request->string('contact')->toString() : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $offer = $this->offers->saveDraft($this->orgId(), $this->validated($request), null, $request->user()?->id);

        return redirect("/offers/{$offer->id}")->with('success', __('offers::of.saved'));
    }

    public function show(Offer $offer): Response
    {
        $this->authorizeView();
        $offer->load(['lines', 'contact', 'contactPerson', 'supersedes:id,number', 'supersededBy:id,number,supersedes_id', 'invoiceLinks.invoice', 'invoiceLinks.lines']);
        $balance = $this->offers->balance($offer);

        return $this->page('Offers/Show', [
            'offer' => $this->present($offer) + [
                'balance' => $balance,
                'invoiced' => self::sum(array_column($balance, 'invoiced')),
                'remaining' => self::sum(array_column($balance, 'remaining')),
                'invoices' => $offer->invoiceLinks
                    ->filter(fn (OfferInvoice $link): bool => $link->invoice !== null)
                    ->map(fn (OfferInvoice $link): array => [
                        'id' => $link->invoice->id,
                        'number' => $link->invoice->number,
                        'status' => $link->invoice->status->value,
                        'issue_date' => $link->invoice->issue_date->toDateString(),
                        'total' => (string) $link->invoice->total,
                        'net_from_offer' => self::sum($link->lines->map(fn ($l): string => (string) $l->amount)->all()),
                    ])->values(),
                'can_invoice' => $offer->status === Offer::STATUS_ACCEPTED && collect($balance)->contains(fn (array $b): bool => ! Money::isZero($b['remaining'])),
                'contact_uuid' => $offer->contact?->uuid,
                'supersedes' => $offer->supersedes?->only(['id', 'number']),
                'superseded_by' => $offer->supersededBy?->only(['id', 'number']),
                'has_document' => $offer->document_path !== null,
                'expired' => $offer->isExpired(),
                'sent_at' => $offer->sent_at?->toIso8601String(),
                'decided_at' => $offer->decided_at?->toIso8601String(),
                'source' => $offer->source,
            ],
        ]);
    }

    public function edit(Offer $offer): Response|RedirectResponse
    {
        $this->authorizeWrite();
        if (! $offer->isDraft()) {
            return redirect("/offers/{$offer->id}")->with('error', __('offers::of.only_draft_editable'));
        }

        return $this->page('Offers/Form', $this->formProps($offer->load('lines')));
    }

    public function update(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorizeWrite();
        $this->offers->saveDraft($this->orgId(), $this->validated($request), $offer);

        return redirect("/offers/{$offer->id}")->with('success', __('offers::of.saved'));
    }

    public function destroy(Offer $offer): RedirectResponse
    {
        $this->authorizeDelete();
        $this->offers->delete($offer);

        return redirect('/offers')->with('success', __('offers::of.deleted'));
    }

    public function transition(Offer $offer, string $action): RedirectResponse
    {
        $this->authorizeWrite();
        $this->offers->transition($offer, $action);

        return back()->with('success', __('offers::of.status_changed'));
    }

    public function revise(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorizeWrite();
        $copy = $this->offers->revise($offer, $request->user()?->id);

        return redirect("/offers/{$copy->id}/edit")->with('success', __('offers::of.revision_created', ['number' => $copy->number]));
    }

    /** Choose the offer lines (and amounts, texts) for a new invoice. */
    public function invoiceForm(Offer $offer): Response|RedirectResponse
    {
        $this->authorizeWrite();
        if ($offer->status !== Offer::STATUS_ACCEPTED) {
            return redirect("/offers/{$offer->id}")->with('error', __('offers::of.invoice_needs_accepted'));
        }
        $offer->load('lines');
        $balance = $this->offers->balance($offer);

        return $this->page('Offers/Invoice', [
            'offer' => ['id' => $offer->id, 'number' => $offer->number, 'title' => $offer->title, 'currency' => $offer->currency, 'vat_rate' => $offer->vat_rate],
            'lines' => $offer->lines->filter(fn (OfferLine $l): bool => $l->isItem())->map(fn (OfferLine $l): array => [
                'id' => $l->id,
                'label' => $l->label,
                'description' => $l->description,
                'quantity' => (string) $l->quantity,
                'unit' => $l->unit,
                'unit_price' => (string) $l->unit_price,
                'invoice_text' => $this->offers->invoiceDescription($l),
            ] + $balance[$l->id])->values(),
        ]);
    }

    public function invoice(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorizeWrite();
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.amount' => ['required', 'numeric', 'regex:/^-?\d{1,9}(\.\d{1,2})?$/'],
            'lines.*.description' => ['required', 'string', 'max:5000'],
        ]);
        $invoice = $this->offers->createInvoice($offer, $data['lines']);

        return redirect("/invoices/{$invoice->id}")->with('success', __('offers::of.invoice_created', ['number' => $invoice->number]));
    }

    public function saveAsTemplate(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorizeWrite();
        $name = $request->validate(['name' => ['required', 'string', 'max:255']])['name'];
        $template = $this->offers->saveAsTemplate($offer->load('lines'), $name);

        return redirect("/offer-templates/{$template->id}/edit")->with('success', __('offers::of.template_saved'));
    }

    /** The stored document once sent (or migrated), otherwise the document drawn now. */
    public function document(Request $request, Offer $offer): HttpResponse
    {
        $this->authorizeView();
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        if ($offer->document_path !== null && Storage::disk('local')->exists($offer->document_path)) {
            $name = $offer->document_name ?? basename($offer->document_path);

            return Storage::disk('local')->response($offer->document_path, $name, [], $disposition);
        }

        return response($this->pdf->render($offer), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$offer->number.'.pdf"',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'contact_id' => ['required', 'integer'],
            'contact_person_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:20000'],
            'closing' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'offer_date' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:offer_date'],
            'request_date' => ['nullable', 'date_format:Y-m-d'],
            'language' => ['required', 'in:fr,de,it,en'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'vat_rate_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'uuid'],
            ...$this->lineRules(1),
        ]);
    }

    /** @return array<string, mixed> */
    private function formProps(?Offer $offer): array
    {
        $organization = Organization::query()->findOrFail($this->orgId());

        return [
            'offer' => $offer ? $this->present($offer) : null,
            'contacts' => Contact::query()->with('contactPersons')->orderBy('name')->get()
                ->map(fn (Contact $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'city' => $c->city,
                    'persons' => $c->contactPersons
                        ->sortByDesc('is_primary')
                        ->map(fn (ContactPerson $p): array => ['id' => $p->id, 'name' => $p->full_name, 'email' => $p->email, 'is_primary' => (bool) $p->is_primary])
                        ->values()
                        ->all(),
                ]),
            'vatRates' => VatRate::query()
                ->where(fn ($q) => $q->where('is_active', true)->when($offer?->vat_rate_id, fn ($q, $id) => $q->orWhere('id', $id)))
                ->orderBy('rate')->get(['id', 'name', 'rate', 'is_default']),
            'templates' => OfferTemplate::query()->orderBy('name')->get(['id', 'name', 'title', 'intro', 'closing', 'lines', 'is_default']),
            'defaults' => [
                'language' => in_array($organization->locale, ['fr', 'de', 'it', 'en'], true) ? $organization->locale : 'fr',
                'currency' => $organization->currency ?: 'CHF',
                'validity_months' => OfferSetting::for($this->orgId())->validity_months,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'number' => $offer->number,
            'status' => $offer->status,
            'contact_id' => $offer->contact_id,
            'contact_person_id' => $offer->contact_person_id,
            'recipient' => $offer->recipient,
            'title' => $offer->title,
            'intro' => $offer->intro,
            'closing' => $offer->closing,
            'notes' => $offer->notes,
            'offer_date' => $offer->offer_date->toDateString(),
            'valid_until' => $offer->valid_until?->toDateString(),
            'request_date' => $offer->request_date?->toDateString(),
            'language' => $offer->language,
            'currency' => $offer->currency,
            'vat_rate_id' => $offer->vat_rate_id,
            'vat_rate' => $offer->vat_rate,
            'template_id' => $offer->template_id,
            'subtotal' => (string) $offer->subtotal,
            'vat_amount' => (string) $offer->vat_amount,
            'total' => (string) $offer->total,
            'lines' => $offer->lines->map(fn (OfferLine $l): array => [
                'id' => $l->id,
                'type' => $l->type,
                'label' => $l->label,
                'description' => $l->description,
                'quantity' => $l->isItem() ? (string) $l->quantity : '',
                'unit' => $l->unit,
                'unit_price' => $l->isItem() ? (string) $l->unit_price : '',
                'amount' => (string) $l->amount,
            ])->values(),
        ];
    }

    /**
     * Per offer, over its item lines and the invoices that still count (not deleted,
     * not cancelled): net invoiced, net remaining and the number of lines not fully
     * invoiced. One query; $offers is an (organisation-scoped) offer query.
     *
     * @param  Builder<Offer>  $offers
     * @return Collection<string, object{offer_id: string, invoiced: string, remaining: string, open_lines: int}>
     */
    private function invoicing(Builder $offers): Collection
    {
        $perLine = OfferInvoiceLine::query()
            ->join('of_offer_invoices as oi', 'oi.id', '=', 'of_offer_invoice_lines.offer_invoice_id')
            ->join('invoices', 'invoices.id', '=', 'oi.invoice_id')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->whereNotNull('of_offer_invoice_lines.offer_line_id')
            ->whereIn('oi.offer_id', (clone $offers)->select('of_offers.id'))
            ->groupBy('of_offer_invoice_lines.offer_line_id')
            ->selectRaw('of_offer_invoice_lines.offer_line_id, SUM(of_offer_invoice_lines.amount) AS invoiced');

        /** @var Collection<string, object{offer_id: string, invoiced: string, remaining: string, open_lines: int}> */
        return DB::table('of_offer_lines as l')
            ->leftJoinSub($perLine, 'inv', 'inv.offer_line_id', '=', 'l.id')
            ->whereIn('l.offer_id', (clone $offers)->select('of_offers.id'))
            ->where('l.type', OfferLine::TYPE_ITEM)
            ->groupBy('l.offer_id')
            ->selectRaw('l.offer_id, COALESCE(SUM(inv.invoiced), 0) AS invoiced, SUM(l.amount - COALESCE(inv.invoiced, 0)) AS remaining, SUM(CASE WHEN l.amount <> COALESCE(inv.invoiced, 0) THEN 1 ELSE 0 END) AS open_lines')
            ->get()
            ->keyBy('offer_id');
    }

    /** @param  array<int|string, string>  $amounts */
    private static function sum(array $amounts): string
    {
        return array_reduce($amounts, fn (string $carry, string $amount): string => Money::add($carry, $amount), '0.00');
    }

    /**
     * none (nothing invoiced), full (no line left) or partial.
     *
     * @param  object{invoiced: string, open_lines: int}|null  $row
     */
    private function invoicingState(?object $row): string
    {
        if ($row === null || Money::isZero(number_format((float) $row->invoiced, 2, '.', ''))) {
            return 'none';
        }

        return (int) $row->open_lines === 0 ? 'full' : 'partial';
    }
}
