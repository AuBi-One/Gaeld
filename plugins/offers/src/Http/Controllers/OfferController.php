<?php

namespace Plugins\Offers\Http\Controllers;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Response;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Models\OfferLine;
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
            ->with(['contact:id,uuid,name', 'invoice:id,number'])
            ->when(in_array($status, Offer::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($status === 'expired', fn ($q) => $q->where('status', Offer::STATUS_SENT)->where('valid_until', '<', $today))
            ->when(ctype_digit($contactId), fn ($q) => $q->where('contact_id', (int) $contactId))
            ->when(preg_match('/^\d{4}$/', $year) === 1, fn ($q) => $q->whereYear('offer_date', (int) $year))
            ->orderByDesc('offer_date')
            ->orderByDesc('number')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Offer $o): array => [
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
                'invoice' => $o->invoice ? ['id' => $o->invoice->id, 'number' => $o->invoice->number] : null,
            ]);

        $open = Offer::query()->where('status', Offer::STATUS_SENT)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today));
        $toInvoice = Offer::query()->where('status', Offer::STATUS_ACCEPTED)
            ->whereDoesntHave('invoice');

        return $this->page('Offers/Index', [
            'offers' => $offers,
            'filters' => ['status' => $status, 'contact' => $contactId, 'year' => $year],
            'statuses' => self::FILTERS,
            'contacts' => Contact::query()->whereIn('id', Offer::query()->select('contact_id'))->orderBy('name')->get(['id', 'name']),
            'years' => Offer::query()->selectRaw('DISTINCT EXTRACT(YEAR FROM offer_date)::int AS y')->orderByDesc('y')->pluck('y'),
            'stats' => [
                'open' => ['count' => (clone $open)->count(), 'total' => (string) (clone $open)->sum('total')],
                'to_invoice' => ['count' => (clone $toInvoice)->count(), 'total' => (string) (clone $toInvoice)->sum('total')],
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
        $offer->load(['lines', 'contact', 'contactPerson', 'supersedes:id,number', 'supersededBy:id,number,supersedes_id', 'invoice:id,number,status']);

        return $this->page('Offers/Show', [
            'offer' => $this->present($offer) + [
                'contact_uuid' => $offer->contact?->uuid,
                'supersedes' => $offer->supersedes?->only(['id', 'number']),
                'superseded_by' => $offer->supersededBy?->only(['id', 'number']),
                'invoice' => $offer->invoice ? ['id' => $offer->invoice->id, 'number' => $offer->invoice->number] : null,
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

    public function invoice(Offer $offer): RedirectResponse
    {
        $this->authorizeWrite();
        $invoice = $this->offers->createInvoice($offer);

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
            'templates' => OfferTemplate::query()->orderBy('name')->get(['id', 'name', 'title', 'intro', 'closing', 'validity_days', 'lines', 'is_default']),
            'defaults' => [
                'language' => in_array($organization->locale, ['fr', 'de', 'it', 'en'], true) ? $organization->locale : 'fr',
                'currency' => $organization->currency ?: 'CHF',
                'validity_days' => (int) config('offers.default_validity_days', 30),
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
}
