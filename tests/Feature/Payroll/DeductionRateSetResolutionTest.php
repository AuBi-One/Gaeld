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
 * Proves that payroll calculation resolves the deduction rate set
 * automatically from the employee's code and the payroll period's date —
 * not just the built-in defaults — and picks the right one when the same
 * code has a different set (different rates) for different years.
 */
class DeductionRateSetResolutionTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2020-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_uses_the_set_matching_the_employees_code_and_the_payroll_period(): void
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'SPECIAL',
            'title' => 'Classe spéciale 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '10.0000',
            'type' => 'employee',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employer',
            'rate' => '10.0000',
            'type' => 'employer',
        ]);

        $this->employee->update(['deduction_rate_code' => 'SPECIAL']);

        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);

        // 10% of 6000.00, not the built-in default (5.3%).
        $this->assertSame('600.00', $slip->deductions['avs_employee']);
    }

    #[Test]
    public function it_picks_the_set_whose_date_range_covers_the_period_when_the_same_code_has_several(): void
    {
        $set2025 = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Barème 2025',
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]);
        $set2025->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.0000',
            'type' => 'employee',
        ]);

        $set2026 = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Barème 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $set2026->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '8.0000',
            'type' => 'employee',
        ]);

        $this->employee->update(['deduction_rate_code' => 'STANDARD']);

        $slip2025 = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 6, 2025);
        $slip2026 = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 6, 2026);

        $this->assertSame('300.00', $slip2025->deductions['avs_employee']);
        $this->assertSame('480.00', $slip2026->deductions['avs_employee']);
    }

    #[Test]
    public function it_falls_back_to_built_in_defaults_when_the_employee_has_no_code(): void
    {
        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);

        // Built-in default AVS rate is 5.3%.
        $this->assertSame('318.00', $slip->deductions['avs_employee']);
    }

    #[Test]
    public function it_falls_back_to_defaults_when_no_set_covers_the_period(): void
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'SPECIAL',
            'title' => 'Classe spéciale 2025',
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '10.0000',
            'type' => 'employee',
        ]);

        $this->employee->update(['deduction_rate_code' => 'SPECIAL']);

        // 2026 is outside the only set's range (2025) — falls back to defaults.
        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);

        $this->assertSame('318.00', $slip->deductions['avs_employee']);
    }
}
