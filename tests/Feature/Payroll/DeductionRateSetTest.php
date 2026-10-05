<?php

namespace Tests\Feature\Payroll;

use App\Domains\Payroll\Models\DeductionRateSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class DeductionRateSetTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_the_page_renders_and_seeds_defaults_for_a_fresh_organization(): void
    {
        $this->assertSame(0, DeductionRateSet::where('organization_id', $this->org->id)->count());

        $response = $this->actAsOrg()->get('/payroll/deduction-rates');

        $response->assertOk();
        $this->assertDatabaseHas('deduction_rate_sets', [
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
        ]);
    }

    public function test_it_creates_a_deduction_rate_set(): void
    {
        $response = $this->actAsOrg()->post('/payroll/deduction-rate-sets', [
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deduction_rate_sets', [
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
        ]);
    }

    public function test_it_rejects_an_overlapping_period_for_the_same_code(): void
    {
        DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rate-sets', [
            'code' => 'STANDARD',
            'title' => 'Standard mid-year change',
            'date_from' => '2026-06-01',
            'date_to' => '2026-12-31',
        ]);

        $response->assertSessionHasErrors('date_from');
        $this->assertDatabaseMissing('deduction_rate_sets', ['title' => 'Standard mid-year change']);
    }

    public function test_a_different_code_may_use_the_same_period(): void
    {
        DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rate-sets', [
            'code' => 'CADRE',
            'title' => 'Cadre 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deduction_rate_sets', ['code' => 'CADRE']);
    }

    public function test_it_updates_a_set_title_and_dates_without_changing_its_code(): void
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response = $this->actAsOrg()->put("/payroll/deduction-rate-sets/{$set->id}", [
            'title' => 'Standard 2026 (révisé)',
            'date_from' => '2026-01-01',
            'date_to' => '2026-11-30',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deduction_rate_sets', [
            'id' => $set->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026 (révisé)',
            'date_to' => '2026-11-30',
        ]);
    }

    public function test_it_rejects_updating_a_set_to_overlap_another_one_with_the_same_code(): void
    {
        DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2025',
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]);
        $set2026 = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response = $this->actAsOrg()->put("/payroll/deduction-rate-sets/{$set2026->id}", [
            'title' => 'Standard 2026',
            'date_from' => '2025-06-01',
            'date_to' => '2026-12-31',
        ]);

        $response->assertSessionHasErrors('date_from');
    }

    public function test_it_deletes_a_set_and_its_lines(): void
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $rate = $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $response = $this->actAsOrg()->delete("/payroll/deduction-rate-sets/{$set->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('deduction_rate_sets', ['id' => $set->id]);
        $this->assertDatabaseMissing('deduction_rates', ['id' => $rate->id]);
    }

    public function test_it_duplicates_a_set_with_its_lines_for_a_new_period(): void
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employer',
            'rate' => '5.3000',
            'type' => 'employer',
        ]);

        $response = $this->actAsOrg()->post("/payroll/deduction-rate-sets/{$set->id}/duplicate", [
            'title' => 'Standard 2027',
            'date_from' => '2027-01-01',
            'date_to' => '2027-12-31',
        ]);

        $response->assertRedirect();

        $newSet = DeductionRateSet::where('title', 'Standard 2027')->first();
        $this->assertNotNull($newSet);
        $this->assertSame('STANDARD', $newSet->code);
        $this->assertSame(2, $newSet->rates()->count());
        $this->assertDatabaseHas('deduction_rates', [
            'deduction_rate_set_id' => $newSet->id,
            'code' => 'avs_employee',
            'rate' => '5.3000',
        ]);
        // The source set keeps its own lines untouched.
        $this->assertSame(2, $set->rates()->count());
    }

    public function test_it_rejects_duplicating_into_an_overlapping_period(): void
    {
        $set2026 = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2027',
            'date_from' => '2027-01-01',
            'date_to' => '2027-12-31',
        ]);

        $response = $this->actAsOrg()->post("/payroll/deduction-rate-sets/{$set2026->id}/duplicate", [
            'title' => 'Standard 2027 (again)',
            'date_from' => '2027-06-01',
            'date_to' => '2027-12-31',
        ]);

        $response->assertSessionHasErrors('date_from');
        $this->assertDatabaseMissing('deduction_rate_sets', ['title' => 'Standard 2027 (again)']);
    }
}
