<?php

namespace Plugins\ExpenseClaims;

use App\Domains\Accounting\Contracts\ClosingCheckInterface;
use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Support\Plugins\PluginNavigation;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Plugins\ExpenseClaims\Console\ImportAirtableCommand;
use Plugins\ExpenseClaims\Listeners\ApprovalNotice;
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
        Event::listen(Login::class, ApprovalNotice::class);

        $navigation = $this->app->make(PluginNavigation::class);
        // Own claims: everyone who can enter expenses; balances: managers only (§8.2, D39).
        $navigation->add(['expenses', 'payroll'], 'expense_claims', 'expense-claims::ec.nav_claims', '/expense-claims', 'expenses.create');
        $navigation->add(['expenses', 'payroll'], 'expense_balances', 'expense-claims::ec.nav_balances', '/expense-balances', 'expenses.approve');
        $navigation->add('organization_settings_nav', 'expense_settings', 'expense-claims::ec.nav_settings', '/settings/expense-claims', 'expenses.approve');
    }
}
