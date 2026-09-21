<?php

namespace Plugins\ExpenseClaims;

use App\Domains\Accounting\Contracts\ClosingCheckInterface;
use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Support\Plugins\PluginNavigation;
use Illuminate\Support\ServiceProvider;
use Plugins\ExpenseClaims\Console\ImportAirtableCommand;
use Plugins\ExpenseClaims\Services\ClosingCheck;
use Plugins\ExpenseClaims\Services\ReimbursementSource;

class ExpenseClaimsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/expense-claims.php', 'expense-claims');
        $this->app->singleton(ReimbursementSourceInterface::class, ReimbursementSource::class);
        $this->app->singleton(ClosingCheck::class);
        $this->app->tag([ClosingCheck::class], ClosingCheckInterface::TAG);
        $this->commands([ImportAirtableCommand::class]);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'expense-claims');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../migrations');

        $navigation = $this->app->make(PluginNavigation::class);
        $navigation->add('payroll', 'expense_claims', 'expense-claims::ec.nav_claims', '/payroll/expense-claims', 'payroll.view');
        $navigation->add('payroll', 'expense_balances', 'expense-claims::ec.nav_balances', '/payroll/expense-balances', 'payroll.view');
        $navigation->add('organization_settings_nav', 'expense_settings', 'expense-claims::ec.nav_settings', '/settings/expense-claims', 'payroll.edit');
    }
}
