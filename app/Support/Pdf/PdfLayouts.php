<?php

namespace App\Support\Pdf;

use App\Domains\Invoicing\Contracts\InvoicePdfLayoutInterface;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Contracts\SalarySlipPdfLayoutInterface;
use Illuminate\Container\Attributes\Singleton;
use InvalidArgumentException;

/**
 * Registry of the PDF layouts other features (e.g. plugins) add for core
 * documents. Nothing is registered in core, and an organisation uses core's
 * standard layout until it chooses another one in its settings
 * (`organizations.pdf_layouts`, document => layout key). A chosen layout that
 * is no longer registered falls back to the standard layout.
 */
#[Singleton]
final class PdfLayouts
{
    public const INVOICE = 'invoice';

    public const SALARY_SLIP = 'salary_slip';

    /** Document => the interface its layouts implement. */
    private const DOCUMENTS = [
        self::INVOICE => InvoicePdfLayoutInterface::class,
        self::SALARY_SLIP => SalarySlipPdfLayoutInterface::class,
    ];

    /** @var array<string, array<string, PdfLayoutInterface>> document => key => layout */
    private array $layouts = [];

    public function register(PdfLayoutInterface $layout): self
    {
        $documents = array_keys(array_filter(self::DOCUMENTS, fn (string $interface): bool => $layout instanceof $interface));
        if ($documents === []) {
            throw new InvalidArgumentException('A PDF layout must implement the layout interface of a document: '.implode(', ', self::DOCUMENTS));
        }
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $layout->key()) !== 1) {
            throw new InvalidArgumentException("Invalid PDF layout key: {$layout->key()}");
        }
        foreach ($documents as $document) {
            $this->layouts[$document][$layout->key()] = $layout;
        }

        return $this;
    }

    public function invoice(Organization $organization): ?InvoicePdfLayoutInterface
    {
        $layout = $this->chosen(self::INVOICE, $organization);

        return $layout instanceof InvoicePdfLayoutInterface ? $layout : null;
    }

    public function salarySlip(Organization $organization): ?SalarySlipPdfLayoutInterface
    {
        $layout = $this->chosen(self::SALARY_SLIP, $organization);

        return $layout instanceof SalarySlipPdfLayoutInterface ? $layout : null;
    }

    /** @return list<string> The documents that can have layouts */
    public function documents(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    /** @return list<string> The keys registered for a document */
    public function keys(string $document): array
    {
        return array_keys($this->layouts[$document] ?? []);
    }

    /**
     * For the settings page: the documents that have layouts, with the
     * choices and the organisation's current one (null = standard).
     *
     * @return list<array{document: string, options: list<array{key: string, label: string}>, current: ?string}>
     */
    public function forSettings(Organization $organization): array
    {
        $result = [];
        foreach (array_keys(self::DOCUMENTS) as $document) {
            if (($this->layouts[$document] ?? []) === []) {
                continue;
            }
            $result[] = [
                'document' => $document,
                'options' => array_values(array_map(fn (PdfLayoutInterface $layout): array => [
                    'key' => $layout->key(),
                    'label' => $layout->label(),
                ], $this->layouts[$document])),
                'current' => $this->chosen($document, $organization)?->key(),
            ];
        }

        return $result;
    }

    private function chosen(string $document, Organization $organization): ?PdfLayoutInterface
    {
        $key = $organization->pdf_layouts[$document] ?? null;

        return is_string($key) ? ($this->layouts[$document][$key] ?? null) : null;
    }
}
