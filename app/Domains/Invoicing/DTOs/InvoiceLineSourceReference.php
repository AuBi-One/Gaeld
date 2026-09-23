<?php

namespace App\Domains\Invoicing\DTOs;

/**
 * The record an invoice line was taken from, as shown next to the line.
 */
final readonly class InvoiceLineSourceReference
{
    public function __construct(
        public string $label,
        public ?string $url = null,
    ) {}

    /** @return array{label: string, url: ?string} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'url' => $this->url];
    }
}
