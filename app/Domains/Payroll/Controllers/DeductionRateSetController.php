<?php

namespace App\Domains\Payroll\Controllers;

use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Controllers\Concerns\EnsuresPayrollWritable;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Queries\DeductionRateSetQuery;
use App\Domains\Payroll\Requests\DuplicateDeductionRateSetRequest;
use App\Domains\Payroll\Requests\StoreDeductionRateSetRequest;
use App\Domains\Payroll\Requests\UpdateDeductionRateSetRequest;
use App\Domains\Payroll\Services\SwissDeductionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deduction rate sets ("barèmes") — a named, dated header an employee's
 * `deduction_rate_code` resolves to at payroll time. Own full-width page
 * under Payroll ("Salaires" → Charges sociales).
 */
class DeductionRateSetController extends Controller
{
    use EnsuresPayrollWritable;

    public function index(CurrentOrganization $currentOrg): Response
    {
        $organization = $currentOrg->get();
        $this->ensurePayrollWritable($organization);
        $this->authorize('update', $organization);

        if ($organization->deductionRateSets()->count() === 0) {
            self::seedDefaults($organization->id);
        }

        return Inertia::render('Payroll/DeductionRates', [
            'deductionRateSets' => DeductionRateSetQuery::all(),
            'deductionRateAccounts' => Account::where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name]),
        ]);
    }

    public function store(StoreDeductionRateSetRequest $request, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('update', $currentOrg->get());

        DeductionRateSet::create([
            'organization_id' => $currentOrg->id(),
            ...$request->validated(),
        ]);

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_set_created'));
    }

    public function update(UpdateDeductionRateSetRequest $request, DeductionRateSet $deductionRateSet, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('update', $currentOrg->get());

        $deductionRateSet->update($request->validated());

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_set_updated'));
    }

    public function destroy(DeductionRateSet $deductionRateSet, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('update', $currentOrg->get());

        $deductionRateSet->delete();

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_set_deleted'));
    }

    /**
     * Create a new period for the same code, copying all of the source
     * set's lines (rate, type, account) — the quick way to "roll over" a
     * barème to a new year before adjusting the numbers that changed.
     */
    public function duplicate(DuplicateDeductionRateSetRequest $request, DeductionRateSet $deductionRateSet, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('update', $currentOrg->get());

        $validated = $request->validated();

        $newSet = DeductionRateSet::create([
            'organization_id' => $currentOrg->id(),
            'code' => $deductionRateSet->code,
            'title' => $validated['title'],
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'],
        ]);

        foreach ($deductionRateSet->rates as $rate) {
            $newSet->rates()->create([
                'organization_id' => $currentOrg->id(),
                'name' => $rate->name,
                'code' => $rate->code,
                'rate' => $rate->rate,
                'type' => $rate->type,
                'account_id' => $rate->account_id,
                'is_active' => $rate->is_active,
            ]);
        }

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_set_duplicated'));
    }

    /**
     * Seed a "STANDARD" set from the built-in defaults, covering the current
     * calendar year, the first time an organization opens this page with
     * none configured yet. Mirrors ExpenseCategoryController's seedDefaults.
     */
    public static function seedDefaults(string $organizationId): void
    {
        $set = DeductionRateSet::withoutGlobalScopes()->create([
            'organization_id' => $organizationId,
            'code' => 'STANDARD',
            'title' => 'Standard',
            'date_from' => Carbon::now()->startOfYear()->toDateString(),
            'date_to' => Carbon::now()->endOfYear()->toDateString(),
        ]);

        foreach (SwissDeductionService::defaults() as $default) {
            $set->rates()->create([
                'organization_id' => $organizationId,
                'name' => $default['name'],
                'code' => $default['code'],
                'rate' => $default['rate'],
                'type' => $default['type'],
                'is_active' => true,
            ]);
        }
    }
}
