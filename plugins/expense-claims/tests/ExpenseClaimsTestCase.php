<?php

namespace Plugins\ExpenseClaims\Tests;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Payroll\Models\Employee;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Boots the application with plugins enabled (phpunit.xml disables them for
 * the core suite) and makes sure the plugin tables exist even when the
 * database was migrated by an earlier core test.
 */
abstract class ExpenseClaimsTestCase extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected Employee $employee;

    public function createApplication(): Application
    {
        self::setPluginsEnv('true');

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('ec_settings')) {
            $this->artisan('migrate', ['--path' => 'plugins/expense-claims/migrations', '--force' => true]);
        }

        $this->setUpOrganization();

        foreach ([
            ['1020', 'Bank', AccountType::Asset],
            ['5000', 'Salaries', AccountType::Expense],
            ['5700', 'Social Charges', AccountType::Expense],
            ['6530', 'General Expense', AccountType::Expense],
            ['2270', 'AVS Payable', AccountType::Liability],
            ['2271', 'AC Payable', AccountType::Liability],
            ['2272', 'LPP Payable', AccountType::Liability],
            ['2273', 'Withholding Tax Payable', AccountType::Liability],
        ] as [$code, $name, $type]) {
            Account::create(['organization_id' => $this->org->id, 'code' => $code, 'name' => $name, 'type' => $type->value]);
        }

        $this->employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Muster',
            'entry_date' => '2024-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::setPluginsEnv('false');
    }

    private static function setPluginsEnv(string $value): void
    {
        putenv("PLUGINS_ENABLED={$value}");
        $_ENV['PLUGINS_ENABLED'] = $value;
        $_SERVER['PLUGINS_ENABLED'] = $value;
    }
}
