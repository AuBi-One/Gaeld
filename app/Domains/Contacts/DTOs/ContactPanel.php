<?php

namespace App\Domains\Contacts\DTOs;

use InvalidArgumentException;

/**
 * A section contributed to the contact page (e.g. a plugin's records of that
 * contact): a titled table, each row optionally linked.
 *
 * Column types: `text` (shown as is), `money` (a decimal string, formatted in
 * the user's locale with the row's `currency`), `date` (`Y-m-d`, formatted in
 * the user's locale). Links must be app-relative (`/…`) or http(s) URLs;
 * anything else is dropped.
 */
final readonly class ContactPanel
{
    private const TYPES = ['text', 'money', 'date'];

    /** @var list<array{key: string, label: string, align: 'left'|'right', type: 'text'|'money'|'date'}> */
    public array $columns;

    /** @var list<array{cells: array<string, string|null>, href: ?string, currency: ?string}> */
    public array $rows;

    /** @var array{label: string, href: string}|null */
    public ?array $action;

    /**
     * @param  list<array<string, mixed>>  $columns  {key, label, align?: left|right, type?: text|money|date} (checked)
     * @param  list<array{cells: array<string, string|null>, href?: string|null, currency?: string|null}>  $rows
     * @param  array<string, mixed>|null  $action  {label, href}: link in the panel header (e.g. "All …")
     */
    public function __construct(
        public string $title,
        array $columns,
        array $rows,
        public ?string $emptyText = null,
        ?array $action = null,
    ) {
        $this->columns = array_map(function (array $column): array {
            $align = $column['align'] ?? 'left';
            $type = $column['type'] ?? 'text';
            if (! is_string($column['key'] ?? null) || ! is_string($column['label'] ?? null)
                || ! in_array($align, ['left', 'right'], true) || ! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException('Invalid contact panel column');
            }

            return ['key' => $column['key'], 'label' => $column['label'], 'align' => $align, 'type' => $type];
        }, $columns);

        $this->rows = array_map(fn (array $row): array => [
            'cells' => $row['cells'],
            'href' => self::safeHref($row['href'] ?? null),
            // an ISO 4217 code, or none (the page formats money with it)
            'currency' => preg_match('/^[A-Z]{3}$/', (string) ($row['currency'] ?? '')) === 1 ? $row['currency'] : null,
        ], $rows);

        $href = is_string($action['href'] ?? null) ? self::safeHref($action['href']) : null;
        $this->action = $href !== null && is_string($action['label'] ?? null) ? ['label' => $action['label'], 'href' => $href] : null;
    }

    /** An app-relative path or an http(s) URL; null otherwise (e.g. `javascript:`, `//host`, `/\\host`). */
    public static function safeHref(?string $href): ?string
    {
        if ($href === null) {
            return null;
        }

        return preg_match('#^/(?![/\\\\])#', $href) === 1 || preg_match('#^https?://#i', $href) === 1
            ? $href
            : null;
    }

    /**
     * @return array{title: string, columns: list<array{key: string, label: string, align: 'left'|'right', type: 'text'|'money'|'date'}>, rows: list<array{cells: array<string, string|null>, href: ?string, currency: ?string}>, empty_text: ?string, action: array{label: string, href: string}|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'empty_text' => $this->emptyText,
            'action' => $this->action,
        ];
    }
}
