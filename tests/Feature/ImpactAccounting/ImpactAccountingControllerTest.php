<?php

namespace Tests\Feature\ImpactAccounting;

use App\Domains\ImpactAccounting\Models\CapitalObservation;
use App\Domains\ImpactAccounting\Models\PreservationAction;
use App\Domains\ImpactAccounting\Models\PreservationCapital;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class ImpactAccountingControllerTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_enabled_organization_can_view_the_preservation_summary(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);
        PreservationCapital::create(['name' => 'Climate']);

        $this->actAsOrg()
            ->get(route('impact-accounting.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ImpactAccounting/Index')
                ->where('summary.capital_count', 1)
                ->where('summary.capitals.0.name', 'Climate'));
    }

    public function test_disabled_organization_cannot_view_the_preservation_summary(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => false],
        ]);

        $this->actAsOrg()
            ->get(route('impact-accounting.index'))
            ->assertForbidden();
    }

    public function test_enabled_organization_can_create_a_capital_with_provenance(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);

        $this->actAsOrg()
            ->post(route('impact-accounting.capitals.store'), [
                'name' => 'Climate',
                'state_translator' => 'Annual carbon budget',
                'unit' => 'tCO2e',
                'threshold' => 'Science-based budget',
                'threshold_direction' => 'maximum',
                'source_reference' => 'Pilot workshop with climate advisor',
            ])
            ->assertRedirect(route('impact-accounting.index'));

        $this->assertDatabaseHas('preservation_capitals', [
            'organization_id' => $this->organization->id,
            'name' => 'Climate',
            'source_reference' => 'Pilot workshop with climate advisor',
        ]);
    }

    public function test_capital_creation_requires_provenance(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);

        $this->actAsOrg()
            ->post(route('impact-accounting.capitals.store'), [
                'name' => 'Climate',
            ])
            ->assertSessionHasErrors('source_reference');
    }

    public function test_enabled_organization_can_record_an_observation_for_its_capital(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);
        $capital = PreservationCapital::create(['name' => 'Climate']);

        $this->actAsOrg()
            ->post(route('impact-accounting.observations.store', $capital), [
                'observed_on' => '2026-03-31',
                'value' => '8.2',
                'unit' => 'tCO2e',
                'source_reference' => 'Quarterly travel report',
                'confidence' => 'estimated',
                'notes' => 'Includes business travel only.',
            ])
            ->assertRedirect(route('impact-accounting.index'));

        $this->assertDatabaseHas('capital_observations', [
            'organization_id' => $this->organization->id,
            'preservation_capital_id' => $capital->id,
            'value' => '8.2',
            'source_reference' => 'Quarterly travel report',
        ]);
    }

    public function test_observation_creation_requires_provenance(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);
        $capital = PreservationCapital::create(['name' => 'Climate']);

        $this->actAsOrg()
            ->post(route('impact-accounting.observations.store', $capital), [
                'observed_on' => '2026-03-31',
                'value' => '8.2',
            ])
            ->assertSessionHasErrors('source_reference');
    }

    public function test_enabled_organization_can_record_an_activity_impact(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);
        $capital = PreservationCapital::create(['name' => 'Climate']);

        $this->actAsOrg()
            ->post(route('impact-accounting.impacts.store', $capital), [
                'activity_name' => 'Business travel',
                'impact_type' => 'direct_emissions',
                'impact_direction' => 'adverse',
                'occurred_on' => '2026-03-31',
                'value' => '2.4',
                'unit' => 'tCO2e',
                'source_reference' => 'Travel report',
            ])
            ->assertRedirect(route('impact-accounting.index'));

        $this->assertDatabaseHas('capital_impacts', [
            'organization_id' => $this->organization->id,
            'preservation_capital_id' => $capital->id,
            'activity_name' => 'Business travel',
            'impact_direction' => 'adverse',
        ]);
    }

    public function test_activity_impact_creation_requires_provenance(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);
        $capital = PreservationCapital::create(['name' => 'Climate']);

        $this->actAsOrg()
            ->post(route('impact-accounting.impacts.store', $capital), [
                'activity_name' => 'Business travel',
                'impact_type' => 'direct_emissions',
                'impact_direction' => 'adverse',
                'occurred_on' => '2026-03-31',
            ])
            ->assertSessionHasErrors('source_reference');
    }

    public function test_enabled_organization_can_plan_a_preservation_action(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);
        $capital = PreservationCapital::create(['name' => 'Climate']);

        $this->actAsOrg()
            ->post(route('impact-accounting.actions.store', $capital), [
                'title' => 'Reduce business travel',
                'description' => 'Replace eligible trips with remote meetings.',
                'action_type' => 'avoidance',
                'status' => 'planned',
                'due_on' => '2026-12-31',
            ])
            ->assertRedirect(route('impact-accounting.index'));

        $this->assertDatabaseHas('preservation_actions', [
            'organization_id' => $this->organization->id,
            'preservation_capital_id' => $capital->id,
            'title' => 'Reduce business travel',
            'action_type' => 'avoidance',
        ]);
    }

    public function test_action_creation_rejects_unknown_action_type(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);
        $capital = PreservationCapital::create(['name' => 'Climate']);

        $this->actAsOrg()
            ->post(route('impact-accounting.actions.store', $capital), [
                'title' => 'Invalid action',
                'action_type' => 'offset',
                'status' => 'planned',
            ])
            ->assertSessionHasErrors('action_type');
    }

    public function test_capital_from_another_organization_cannot_be_used_for_an_impact(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);
        $otherOrganization = Organization::factory()->create();
        $otherCapital = PreservationCapital::withoutGlobalScopes()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other climate capital',
        ]);

        $this->actAsOrg()
            ->post(route('impact-accounting.impacts.store', $otherCapital), [
                'activity_name' => 'Business travel',
                'impact_type' => 'direct_emissions',
                'impact_direction' => 'adverse',
                'occurred_on' => '2026-03-31',
                'source_reference' => 'Travel report',
            ])
            ->assertNotFound();
    }

    public function test_observation_can_be_updated_without_leaving_its_organization(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);
        $observation = CapitalObservation::create([
            'organization_id' => $this->organization->id,
            'preservation_capital_id' => PreservationCapital::create(['name' => 'Climate'])->id,
            'observed_on' => '2026-03-31',
            'value' => '8.2',
            'source_reference' => 'Initial estimate',
        ]);

        $this->actAsOrg()
            ->put(route('impact-accounting.observations.update', $observation), [
                'observed_on' => '2026-04-30',
                'value' => '7.9',
                'unit' => 'tCO2e',
                'source_reference' => 'Reconciled travel report',
                'confidence' => 'measured',
            ])
            ->assertRedirect(route('impact-accounting.index'));

        $this->assertDatabaseHas('capital_observations', [
            'id' => $observation->id,
            'observed_on' => '2026-04-30',
            'value' => '7.9',
            'source_reference' => 'Reconciled travel report',
        ]);
    }

    public function test_preservation_action_can_be_updated(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);
        $action = PreservationAction::create([
            'organization_id' => $this->organization->id,
            'preservation_capital_id' => PreservationCapital::create(['name' => 'Climate'])->id,
            'title' => 'Reduce travel',
            'action_type' => 'avoidance',
            'status' => 'planned',
        ]);

        $this->actAsOrg()
            ->put(route('impact-accounting.actions.update', $action), [
                'title' => 'Reduce business travel',
                'description' => 'Replace eligible trips with remote meetings.',
                'action_type' => 'avoidance',
                'status' => 'in_progress',
                'due_on' => '2026-12-31',
            ])
            ->assertRedirect(route('impact-accounting.index'));

        $this->assertDatabaseHas('preservation_actions', [
            'id' => $action->id,
            'title' => 'Reduce business travel',
            'status' => 'in_progress',
        ]);
    }

    public function test_enabled_organization_can_document_a_phase_one_activity(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);

        $this->actAsOrg()
            ->post(route('impact-accounting.activities.store'), [
                'name' => 'Deliver client software',
                'purpose' => 'Provide accounting software to small businesses.',
                'inputs' => 'Employee time, electricity, cloud hosting.',
                'outputs' => 'Software service and customer support.',
                'source_reference' => 'Phase 1A workshop notes',
            ])
            ->assertRedirect(route('impact-accounting.index'));

        $this->assertDatabaseHas('organization_activities', [
            'organization_id' => $this->organization->id,
            'name' => 'Deliver client software',
            'status' => 'draft',
        ]);
    }

    public function test_activity_documentation_requires_a_source(): void
    {
        $this->organization->update([
            'enabled_modules' => ['impact_accounting' => true],
        ]);

        $this->actAsOrg()
            ->post(route('impact-accounting.activities.store'), [
                'name' => 'Deliver client software',
            ])
            ->assertSessionHasErrors('source_reference');
    }
}
