<?php

namespace App\Domains\Payroll\Queries;

use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Services\SwissDeductionService;
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
     * Human-readable name for every deduction code that could appear on a
     * slip calculated for this employee/date: the resolved set's own names,
     * filled in with the built-in defaults' names for any code the set
     * doesn't have (the fallback used when no set applies). Used to label
     * the per-charge rows on a salary slip from just its `deductions`
     * array, which only carries codes.
     *
     * @return array<string, string>
     */
    public static function namesFor(string $organizationId, ?string $code, string $date): array
    {
        $names = [];

        $set = self::resolve($organizationId, $code, $date);
        if ($set) {
            foreach ($set->rates as $rate) {
                $names[$rate->code] = $rate->name;
            }
        }

        foreach (SwissDeductionService::defaults() as $default) {
            $names[$default['code']] ??= $default['name'];
        }

        return $names;
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
