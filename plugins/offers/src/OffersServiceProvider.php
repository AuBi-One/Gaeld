<?php

namespace Plugins\Offers;

use App\Support\Plugins\PluginNavigation;
use Illuminate\Support\ServiceProvider;

class OffersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/offers.php', 'offers');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'offers');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'offers');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../migrations');

        $navigation = $this->app->make(PluginNavigation::class);
        // Top-level entry in the Activity section, right after Invoices (hidden where invoices
        // are, e.g. fiduciary organisations); templates are reached from the offers page.
        $navigation->add('after:invoices', 'offers', 'offers::of.nav_offers', '/offers', 'invoicing.view', 'FilePen');
    }
}
