<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Contracts\InvoiceLineSourceInterface;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Container\Attributes\Singleton;

/**
 * Registry of the records invoice lines can be taken from (none in core;
 * plugins register theirs from a service provider).
 */
#[Singleton]
final class InvoiceLineSources
{
    /** @var array<string, InvoiceLineSourceInterface> */
    private array $sources = [];

    public function register(InvoiceLineSourceInterface $source): self
    {
        $this->sources[$source->type()] = $source;

        return $this;
    }

    public function get(string $type): ?InvoiceLineSourceInterface
    {
        return $this->sources[$type] ?? null;
    }

    /**
     * For the invoice form: one "add line from …" action per source.
     *
     * @return list<array{type: string, label: string, picker_url: string}>
     */
    public function forFrontend(): array
    {
        return array_values(array_map(fn (InvoiceLineSourceInterface $s): array => [
            'type' => $s->type(),
            'label' => $s->label(),
            'picker_url' => $s->pickerUrl(),
        ], $this->sources));
    }

    /** Whether `$sourceId` of `$type` is a record of the organisation. */
    public function exists(string $organizationId, string $type, string $sourceId): bool
    {
        return isset($this->get($type)?->describe($organizationId, [$sourceId])[$sourceId]);
    }

    /**
     * The source of each line of the invoice that has one (and still exists).
     *
     * @return array<int, array{label: string, url: ?string}> invoice line id => reference
     */
    public function forInvoice(Invoice $invoice): array
    {
        $lines = $invoice->lines->filter(fn ($line): bool => $line->source_type !== null && $line->source_id !== null);
        $result = [];
        foreach ($lines->groupBy('source_type') as $type => $group) {
            $ids = array_values(array_unique(array_map('strval', $group->pluck('source_id')->all())));
            $references = $this->get((string) $type)?->describe($invoice->organization_id, $ids) ?? [];
            foreach ($group as $line) {
                if (isset($references[$line->source_id])) {
                    $result[$line->id] = $references[$line->source_id]->toArray();
                }
            }
        }

        return $result;
    }
}
