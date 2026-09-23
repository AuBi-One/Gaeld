<?php

namespace App\Domains\Accounting\Queries;

use App\Domains\Accounting\Models\JournalEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Journal list and export: filters, sorting and page size read from the query string.
 *
 * Invalid values are ignored (the default applies) rather than rejected,
 * so a stale or hand-edited URL still shows the list.
 *
 * @phpstan-type JournalListParams array{from: ?string, to: ?string, accounts: list<int>, reference: string, description: string, status: ?string, sort: string, direction: 'asc'|'desc', per_page: int}
 */
class JournalEntryQuery
{
    /** Sortable columns (request key => SQL column or select alias). */
    public const SORTS = [
        'date' => 'journal_entries.date',
        'reference' => 'journal_entries.reference',
        'description' => 'journal_entries.description',
        'status' => 'journal_entries.is_posted',
        'amount' => 'amount',
    ];

    public const PER_PAGE_OPTIONS = [20, 50, 100, 200];

    /** Upper bound of selected accounts in one filter. */
    public const MAX_ACCOUNTS = 50;

    /**
     * @return JournalListParams
     */
    public static function params(Request $request): array
    {
        $sort = $request->query('sort');
        $sort = is_string($sort) && array_key_exists($sort, self::SORTS) ? $sort : 'date';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $perPage = $request->query('per_page');
        $perPage = is_string($perPage) && in_array((int) $perPage, self::PER_PAGE_OPTIONS, true)
            ? (int) $perPage
            : self::PER_PAGE_OPTIONS[0];

        $status = $request->query('status');

        return [
            'from' => self::date($request->query('from')),
            'to' => self::date($request->query('to')),
            'accounts' => self::ids($request->query('accounts')),
            'reference' => self::text($request->query('reference')),
            'description' => self::text($request->query('description')),
            'status' => in_array($status, ['draft', 'posted'], true) ? $status : null,
            'sort' => $sort,
            'direction' => $direction,
            'per_page' => $perPage,
        ];
    }

    /**
     * Entries matching the filters (no eager loading, no order).
     *
     * @param  JournalListParams  $params
     * @return Builder<JournalEntry>
     */
    public static function filtered(array $params): Builder
    {
        return JournalEntry::query()
            ->when($params['from'], fn (Builder $q, string $date) => $q->where('journal_entries.date', '>=', $date))
            ->when($params['to'], fn (Builder $q, string $date) => $q->where('journal_entries.date', '<=', $date))
            ->when($params['status'], fn (Builder $q, string $status) => $q->where('journal_entries.is_posted', $status === 'posted'))
            // EXISTS on transaction_lines(account_id, journal_entry_id): index idx_txn_lines_account_entry.
            ->when($params['accounts'] !== [], fn (Builder $q) => $q->whereHas(
                'lines',
                fn (Builder $lines) => $lines->whereIn('account_id', $params['accounts']),
            ))
            ->when($params['reference'] !== '', fn (Builder $q) => $q->where('journal_entries.reference', 'ilike', self::contains($params['reference'])))
            // The entry description or the description of any of its lines.
            ->when($params['description'] !== '', function (Builder $q) use ($params): void {
                $pattern = self::contains($params['description']);
                $q->where(fn (Builder $w) => $w
                    ->where('journal_entries.description', 'ilike', $pattern)
                    ->orWhereHas('lines', fn (Builder $lines) => $lines->where('description', 'ilike', $pattern)));
            });
    }

    /**
     * @param  JournalListParams  $params
     * @return LengthAwarePaginator<int, JournalEntry>
     */
    public static function list(array $params): LengthAwarePaginator
    {
        $query = self::filtered($params)
            ->with('lines.account')
            ->withSum('lines as amount', 'debit');

        $direction = $params['direction'];
        if ($params['sort'] !== 'date') {
            // Column names come from the SORTS allow-list, never from the request.
            $query->orderByRaw(self::SORTS[$params['sort']].' '.$direction.' NULLS LAST');
        }
        // Stable order for pagination: date, then creation time, then id.
        $dateDirection = $params['sort'] === 'date' ? $direction : 'desc';
        $query->orderBy('journal_entries.date', $dateDirection)
            ->orderBy('journal_entries.created_at', $dateDirection)
            ->orderBy('journal_entries.id', $dateDirection);

        return $query->paginate($params['per_page'])->withQueryString();
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    /**
     * Account ids as a comma-separated string ("12,63"); anything else is dropped.
     *
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }
        $ids = array_filter(explode(',', $value), fn (string $id) => $id !== '' && ctype_digit($id) && strlen($id) <= 18);

        return array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::MAX_ACCOUNTS);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }

    /** ILIKE pattern for "contains", with the LIKE wildcards of the input escaped. */
    private static function contains(string $value): string
    {
        return '%'.addcslashes($value, '\\%_').'%';
    }
}
