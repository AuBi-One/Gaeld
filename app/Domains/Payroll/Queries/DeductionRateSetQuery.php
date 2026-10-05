<?php

namespace App\Domains\Payroll\Queries;

use App\Domains\Payroll\Models\DeductionRateSet;
use Illuminate\Support\Collection;

/**
 * Resolves and lists deduction rate sets ("barèmes") for the current
 * organization.
 */
class DeductionRateSetQuery
{
    /**
     * The set whose code matches and whose date range covers the given date
     * (e.g. the payroll period), for an organization. If several sets of the
     * same code somehow overlap that date, the most recently starting one
     * wins — but StoreDeductionRateSetRequest rejects overlaps on creation,
     * so that should not normally happen.
     */
    public static function resolve(string $organizationId, ?string $code, string $date): ?DeductionRateSet
    {
        if ($code === null || $code === '') {
            return null;
        }

        return DeductionRateSet::where('organization_id', $organizationId)
            ->where('code', $code)
            ->where('date_from', '<=', $date)
            ->where('date_to', '>=', $date)
            ->orderByDesc('date_from')
            ->first();
    }

    /**
     * All sets for the current organization, each with its lines and their
     * mapped account, for the settings page — newest period first within
     * each code.
     *
     * @return Collection<int, DeductionRateSet>
     */
    public static function all(): Collection
    {
        return DeductionRateSet::query()
            ->with(['rates' => fn ($query) => $query->with('account:id,code,name')->orderBy('name')->orderBy('type')])
            ->orderBy('code')
            ->orderByDesc('date_from')
            ->get();
    }

    /**
     * Distinct codes already in use for the current organization, for the
     * employee "deduction rate code" dropdown.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = DeductionRateSet::query()
            ->orderBy('code')
            ->distinct()
            ->pluck('code')
            ->map(fn (mixed $code): string => (string) $code)
            ->all();

        return array_values($codes);
    }
}
