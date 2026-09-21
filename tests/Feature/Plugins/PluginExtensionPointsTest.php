<?php

namespace Tests\Feature\Plugins;

use App\Domains\Accounting\Contracts\ClosingCheckInterface;
use App\Domains\Payroll\Models\Employee;
use App\Support\Plugins\PluginNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Extension points used by plugins: sidebar entries, year-end closing checks
 * and itemised payroll reimbursements (default source offers none).
 */
class PluginExtensionPointsTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_plugin_navigation_entries_are_shared_with_translated_labels(): void
    {
        app(PluginNavigation::class)->add('payroll', 'demo', 'app.payroll', '/payroll/demo', 'payroll.view');
        app(PluginNavigation::class)->add(['expenses', 'payroll'], 'demo2', 'app.expenses', '/demo2');

        $this->actAsOrg()->get('/payroll/employees')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pluginNavigation.0.parent', 'payroll')
                ->where('pluginNavigation.0.href', '/payroll/demo')
                ->where('pluginNavigation.0.text', __('app.payroll'))
                ->where('pluginNavigation.0.permission', 'payroll.view')
                ->where('pluginNavigation.1.parent', 'expenses')
                ->where('pluginNavigation.1.parents', ['expenses', 'payroll']));
    }

    public function test_tagged_closing_checks_reach_the_wizard(): void
    {
        $this->app->instance('demo.check', new class implements ClosingCheckInterface
        {
            public function check(string $organizationId, string $fromDate, string $toDate): array
            {
                return [['key' => 'demo', 'message' => "period {$fromDate} {$toDate}"]];
            }
        });
        $this->app->tag(['demo.check'], ClosingCheckInterface::TAG);

        $this->actAsOrg()->get('/accounting/year-end-closing?year=2025')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('closingChecks.0.key', 'demo')
                ->where('closingChecks.0.message', 'period 2025-01-01 2025-12-31'));
    }

    public function test_without_a_source_item_ids_are_rejected_and_none_are_offered(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ]);

        $this->actAsOrg()->get('/payroll/run')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('reimbursementItems', []));

        $this->actAsOrg()->postJson('/payroll/run/preview', [
            'month' => 3,
            'year' => 2026,
            'employee_ids' => [$employee->id],
            'adjustments' => [['employee_id' => $employee->id, 'reimbursement_item_ids' => ['claim:x']]],
        ])->assertStatus(422);
    }
}
