<?php

namespace Tests\Feature\Payroll;

use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The salary slip detail page must label every deduction actually present
 * on the slip (any code, not a fixed AVS/AC/AANP/LPP list), using the name
 * configured for it in Payroll > Charges sociales.
 */
class SalarySlipDeductionLabelsTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    #[Test]
    public function it_sends_a_name_for_a_custom_deduction_code_not_in_the_built_in_defaults(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
            'deduction_rate_code' => 'SPECIAL',
        ]);

        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'SPECIAL',
            'title' => 'Spécial 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'APGM',
            'code' => 'apgm_employee',
            'rate' => '0.6100',
            'type' => 'employee',
        ]);

        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $slip->save();

        $response = $this->actAsOrg()->get("/payroll/salary-slips/{$slip->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Payroll/SalarySlips/Show')
            ->where('deductionNames.apgm_employee', 'APGM'));
    }

    #[Test]
    public function deduction_rows_include_a_custom_charge_beyond_the_legacy_four(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
            'deduction_rate_code' => 'SPECIAL',
        ]);

        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'SPECIAL',
            'title' => 'Spécial 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'Alloc. familiales',
            'code' => 'family_allowance_employer',
            'rate' => '2.6200',
            'type' => 'employer',
        ]);

        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $slip->save();

        $rows = $slip->deductionRows();
        $familyRow = collect($rows)->firstWhere('name', 'Alloc. familiales');

        $this->assertNotNull($familyRow, 'expected a row for the employer-only custom charge');
        $this->assertSame('0', $familyRow['employee']);
        $this->assertSame($slip->deductions['family_allowance_employer'], $familyRow['employer']);
    }

    #[Test]
    public function it_falls_back_to_the_built_in_default_names_when_the_employee_has_no_code(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ]);

        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $slip->save();

        $response = $this->actAsOrg()->get("/payroll/salary-slips/{$slip->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Payroll/SalarySlips/Show')
            ->has('deductionNames.avs_employee'));
    }
}
