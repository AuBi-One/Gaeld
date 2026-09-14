<?php

namespace Tests\Feature\ImpactAccounting;

use App\Domains\ImpactAccounting\Enums\PreservationActionType;
use App\Domains\ImpactAccounting\Models\CapitalImpact;
use App\Domains\ImpactAccounting\Models\CapitalObservation;
use App\Domains\ImpactAccounting\Models\OrganizationActivity;
use App\Domains\ImpactAccounting\Models\PreservationAction;
use App\Domains\ImpactAccounting\Models\PreservationCapital;
use App\Domains\ImpactAccounting\Services\PreservationSummaryService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreservationCapitalTest extends TestCase
{
    use RefreshDatabase;

    public function test_capital_is_scoped_to_the_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($organization);

        $capital = PreservationCapital::create([
            'name' => 'Climate',
            'state_translator' => 'Organization carbon budget',
            'unit' => 'tCO2e',
            'threshold' => 'Science-based annual budget',
            'threshold_direction' => 'maximum',
            'source_reference' => 'Pilot methodology workshop',
        ]);

        $this->assertNotEmpty($capital->id);
        $this->assertSame($organization->id, $capital->organization_id);
        $this->assertNull($capital->getAttribute('monetary_value'));
        $this->assertTrue(PreservationCapital::whereKey($capital->id)->exists());

        app(CurrentOrganization::class)->set($otherOrganization);

        $this->assertFalse(PreservationCapital::whereKey($capital->id)->exists());
    }

    public function test_observation_keeps_date_value_and_provenance(): void
    {
        $organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($organization);

        $capital = PreservationCapital::create([
            'name' => 'Climate',
            'state_translator' => 'Organization carbon budget',
        ]);
        $observation = $capital->observations()->create([
            'observed_on' => '2026-01-31',
            'value' => '12.4',
            'unit' => 'tCO2e',
            'source_reference' => 'Utility invoices and travel log',
            'confidence' => 'estimated',
        ]);

        $this->assertInstanceOf(CapitalObservation::class, $observation);
        $this->assertSame($capital->id, $observation->preservation_capital_id);
        $this->assertSame('2026-01-31', $observation->observed_on->toDateString());
        $this->assertSame('12.4', $observation->value);
        $this->assertSame('estimated', $observation->confidence);
        $this->assertSame($organization->id, $observation->organization_id);
    }

    public function test_actions_keep_preservation_and_avoidance_distinct(): void
    {
        $organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($organization);

        $capital = PreservationCapital::create(['name' => 'Climate']);
        $restoration = $capital->preservationActions()->create([
            'title' => 'Restore the travel reduction plan',
            'action_type' => PreservationActionType::Restoration,
            'due_on' => '2026-12-31',
        ]);
        $avoidance = $capital->preservationActions()->create([
            'title' => 'Choose lower-emission travel',
            'action_type' => PreservationActionType::Avoidance,
        ]);

        $this->assertInstanceOf(PreservationAction::class, $restoration);
        $this->assertSame(PreservationActionType::Restoration, $restoration->action_type);
        $this->assertSame(PreservationActionType::Avoidance, $avoidance->action_type);
        $this->assertSame($organization->id, $avoidance->organization_id);
        $this->assertCount(2, $capital->preservationActions()->get());
    }

    public function test_activity_impact_is_linked_to_a_capital_without_linking_the_legal_ledger(): void
    {
        $organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($organization);

        $capital = PreservationCapital::create(['name' => 'Climate']);
        $impact = $capital->impacts()->create([
            'activity_name' => 'Business travel',
            'impact_type' => 'direct_emissions',
            'impact_direction' => 'adverse',
            'occurred_on' => '2026-02-15',
            'value' => '1.8',
            'unit' => 'tCO2e',
            'source_reference' => 'Travel expense report',
        ]);

        $this->assertInstanceOf(CapitalImpact::class, $impact);
        $this->assertSame($capital->id, $impact->preservation_capital_id);
        $this->assertSame('Business travel', $impact->activity_name);
        $this->assertSame('adverse', $impact->impact_direction);
        $this->assertSame($organization->id, $impact->organization_id);
        $this->assertDatabaseMissing('journal_entries', ['id' => $impact->id]);
    }

    public function test_summary_returns_latest_observation_and_open_actions_without_composite_score(): void
    {
        $organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($organization);

        $capital = PreservationCapital::create(['name' => 'Climate']);
        OrganizationActivity::create([
            'name' => 'Deliver client software',
            'purpose' => 'Provide accounting software.',
            'source_reference' => 'Workshop notes',
        ]);
        $capital->observations()->createMany([
            [
                'observed_on' => '2026-01-31',
                'value' => '12.4',
                'unit' => 'tCO2e',
                'source_reference' => 'January estimate',
            ],
            [
                'observed_on' => '2026-02-28',
                'value' => '10.1',
                'unit' => 'tCO2e',
                'source_reference' => 'February invoices',
            ],
        ]);
        $capital->preservationActions()->createMany([
            ['title' => 'Restore', 'action_type' => PreservationActionType::Restoration],
            ['title' => 'Done', 'action_type' => PreservationActionType::Prevention, 'status' => 'completed'],
        ]);
        $capital->impacts()->create([
            'activity_name' => 'Business travel',
            'impact_type' => 'direct_emissions',
            'occurred_on' => '2026-02-15',
            'source_reference' => 'Travel report',
        ]);

        $summary = app(PreservationSummaryService::class)->summary($organization->id);
        $capitalSummary = $summary['capitals'][0];

        $this->assertSame(1, $summary['capital_count']);
        $this->assertSame(1, $summary['activity_count']);
        $this->assertSame('Deliver client software', $summary['activities'][0]['name']);
        $this->assertSame('10.1', $capitalSummary['latest_observation']['value']);
        $this->assertSame(1, $capitalSummary['impact_count']);
        $this->assertSame(1, $capitalSummary['open_action_count']);
        $this->assertEqualsCanonicalizing(
            ['restoration' => 1, 'prevention' => 1],
            $capitalSummary['action_counts'],
        );
        $this->assertCount(2, $capitalSummary['observations']);
        $this->assertSame('Business travel', $capitalSummary['impacts'][0]['activity_name']);
        $this->assertCount(2, $capitalSummary['actions']);
        $this->assertArrayNotHasKey('score', $capitalSummary);
    }
}
