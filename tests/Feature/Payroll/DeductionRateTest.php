<?php

namespace Tests\Feature\Payroll;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\DeductionRateSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class DeductionRateTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private DeductionRateSet $set;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
    }

    public function test_it_creates_a_deduction_rate_line_with_different_employee_and_employer_values(): void
    {
        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'APGM',
            'code' => 'apgm_employee',
            'rate' => '0.6100',
            'type' => 'employee',
        ]);
        $response->assertRedirect();

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'APGM',
            'code' => 'apgm_employer',
            'rate' => '0.6100',
            'type' => 'employer',
        ]);
        $response->assertRedirect();

        $this->assertDatabaseHas('deduction_rates', [
            'deduction_rate_set_id' => $this->set->id,
            'code' => 'apgm_employee',
            'type' => 'employee',
        ]);
        $this->assertDatabaseHas('deduction_rates', [
            'deduction_rate_set_id' => $this->set->id,
            'code' => 'apgm_employer',
            'type' => 'employer',
        ]);
    }

    public function test_it_rejects_a_duplicate_code_within_the_same_set(): void
    {
        DeductionRate::create([
            'organization_id' => $this->org->id,
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'AVS duplicate',
            'code' => 'avs_employee',
            'rate' => '6.0000',
            'type' => 'employee',
        ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_the_same_code_can_be_reused_in_a_different_set(): void
    {
        DeductionRate::create([
            'organization_id' => $this->org->id,
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $otherSet = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2027',
            'date_from' => '2027-01-01',
            'date_to' => '2027-12-31',
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $otherSet->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.5000',
            'type' => 'employee',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deduction_rates', [
            'deduction_rate_set_id' => $otherSet->id,
            'code' => 'avs_employee',
            'rate' => '5.5000',
        ]);
    }

    public function test_it_updates_the_rate_independently_for_employee_and_employer(): void
    {
        $employeeRate = DeductionRate::create([
            'organization_id' => $this->org->id,
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'LPP',
            'code' => 'lpp_employee',
            'rate' => '7.0000',
            'type' => 'employee',
        ]);
        $employerRate = DeductionRate::create([
            'organization_id' => $this->org->id,
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'LPP',
            'code' => 'lpp_employer',
            'rate' => '7.0000',
            'type' => 'employer',
        ]);

        $this->actAsOrg()->put("/payroll/deduction-rates/{$employerRate->id}", [
            'name' => 'LPP',
            'rate' => '11.0000',
            'type' => 'employer',
        ])->assertRedirect();

        $this->assertDatabaseHas('deduction_rates', ['id' => $employeeRate->id, 'rate' => '7.0000']);
        $this->assertDatabaseHas('deduction_rates', ['id' => $employerRate->id, 'rate' => '11.0000']);
    }

    public function test_it_deletes_a_deduction_rate_line(): void
    {
        $rate = DeductionRate::create([
            'organization_id' => $this->org->id,
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'Allocations familiales',
            'code' => 'family_allowance_employer',
            'rate' => '3.1500',
            'type' => 'employer',
        ]);

        $response = $this->actAsOrg()->delete("/payroll/deduction-rates/{$rate->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('deduction_rates', ['id' => $rate->id]);
    }

    public function test_it_creates_a_deduction_rate_with_an_account_mapping(): void
    {
        $account = Account::create([
            'organization_id' => $this->org->id,
            'code' => '2270',
            'name' => 'AVS payable',
            'type' => AccountType::Liability->value,
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'AVS',
            'code' => 'avs_employee_custom',
            'rate' => '5.3000',
            'type' => 'employee',
            'account_id' => $account->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deduction_rates', [
            'deduction_rate_set_id' => $this->set->id,
            'code' => 'avs_employee_custom',
            'account_id' => $account->id,
        ]);
    }

    public function test_it_rejects_an_account_from_another_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignAccount = Account::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'code' => '2270',
            'name' => 'Other org account',
            'type' => AccountType::Liability->value,
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'AVS',
            'code' => 'avs_employee_custom',
            'rate' => '5.3000',
            'type' => 'employee',
            'account_id' => $foreignAccount->id,
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertDatabaseMissing('deduction_rates', ['code' => 'avs_employee_custom']);
    }

    public function test_it_rejects_a_set_from_another_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignSet = DeductionRateSet::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'code' => 'STANDARD',
            'title' => 'Foreign',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $foreignSet->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $response->assertSessionHasErrors('deduction_rate_set_id');
    }

    public function test_it_prevents_updating_a_deduction_rate_from_another_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignSet = DeductionRateSet::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'code' => 'STANDARD',
            'title' => 'Foreign',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $foreignRate = DeductionRate::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'deduction_rate_set_id' => $foreignSet->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $response = $this->actAsOrg()->put("/payroll/deduction-rates/{$foreignRate->id}", [
            'name' => 'Hijacked',
            'rate' => '99.0000',
            'type' => 'employee',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('deduction_rates', ['id' => $foreignRate->id, 'rate' => '5.3000']);
    }

    public function test_it_prevents_deleting_a_deduction_rate_from_another_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignSet = DeductionRateSet::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'code' => 'STANDARD',
            'title' => 'Foreign',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $foreignRate = DeductionRate::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'deduction_rate_set_id' => $foreignSet->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $response = $this->actAsOrg()->delete("/payroll/deduction-rates/{$foreignRate->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('deduction_rates', ['id' => $foreignRate->id]);
    }
}
