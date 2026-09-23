<?php

namespace Plugins\Offers\Models;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Offer (quote) to a client. Only drafts are edited; the document sent to the
 * client is stored when the offer is marked as sent.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $status
 * @property int $contact_id
 * @property string|null $contact_person_id
 * @property array{company: string, attention: string|null, email: string|null, address: string|null, postal_code: string|null, city: string|null, country: string|null, phone?: string|null}|null $recipient
 * @property string $title
 * @property string|null $intro
 * @property string|null $closing
 * @property string|null $notes
 * @property Carbon $offer_date
 * @property Carbon|null $valid_until
 * @property Carbon|null $request_date
 * @property string $language
 * @property string $currency
 * @property int|null $vat_rate_id
 * @property string|null $vat_rate
 * @property string $subtotal
 * @property string $vat_amount
 * @property string $total
 * @property string|null $template_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $decided_at
 * @property string|null $supersedes_id
 * @property array<string, mixed>|null $layout
 * @property string|null $document_path
 * @property string|null $document_name
 * @property int|null $created_by
 * @property string $source
 * @property string|null $external_ref
 * @property-read Contact|null $contact
 * @property-read ContactPerson|null $contactPerson
 * @property-read Collection<int, OfferLine> $lines
 * @property-read Offer|null $supersedes
 * @property-read Offer|null $supersededBy
 * @property-read Collection<int, OfferInvoice> $invoiceLinks
 */
class Offer extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'of_offers';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_ACCEPTED, self::STATUS_REFUSED, self::STATUS_SUPERSEDED];

    protected $fillable = ['organization_id', 'number', 'status', 'contact_id', 'contact_person_id', 'recipient', 'title', 'intro', 'closing', 'notes', 'offer_date', 'valid_until', 'request_date', 'language', 'currency', 'vat_rate_id', 'vat_rate', 'subtotal', 'vat_amount', 'total', 'template_id', 'sent_at', 'decided_at', 'supersedes_id', 'layout', 'document_path', 'document_name', 'created_by', 'source', 'external_ref'];

    protected function casts(): array
    {
        return [
            'recipient' => 'array',
            'layout' => 'array',
            'offer_date' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'request_date' => 'date:Y-m-d',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'vat_rate' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /** @return BelongsTo<ContactPerson, $this> */
    public function contactPerson(): BelongsTo
    {
        return $this->belongsTo(ContactPerson::class);
    }

    /** @return HasMany<OfferLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OfferLine::class)->orderBy('sort');
    }

    /** @return BelongsTo<Offer, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** @return HasOne<Offer, $this> */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    /** @return HasMany<OfferInvoice, $this> */
    public function invoiceLinks(): HasMany
    {
        return $this->hasMany(OfferInvoice::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Sent, still waiting for an answer, and past its validity date. */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_SENT
            && $this->valid_until !== null
            && $this->valid_until->lt(now()->startOfDay());
    }

    /** At least one invoice made from this offer still counts (exists, not cancelled). */
    public function hasInvoice(): bool
    {
        return $this->invoiceLinks()->whereHas('invoice', fn ($q) => $q->where('status', '!=', InvoiceStatus::Cancelled->value))->exists();
    }

    /**
     * Net amount invoiced per offer line, over the invoices that still count.
     *
     * @return array<int, string> offer line id => amount
     */
    public function invoicedByLine(): array
    {
        return OfferInvoiceLine::query()
            ->whereHas('link', fn ($q) => $q->where('offer_id', $this->id)
                ->whereHas('invoice', fn ($q) => $q->where('status', '!=', InvoiceStatus::Cancelled->value)))
            ->whereNotNull('offer_line_id')
            ->groupBy('offer_line_id')
            ->selectRaw('offer_line_id, SUM(amount) AS invoiced')
            ->pluck('invoiced', 'offer_line_id')
            ->map(fn ($v): string => number_format((float) $v, 2, '.', ''))
            ->all();
    }
}
