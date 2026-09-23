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
        $navigation->add('invoices', 'offers', 'offers::of.nav_offers', '/offers', 'invoicing.view');
        $navigation->add('invoices', 'offer_templates', 'offers::of.nav_templates', '/offer-templates', 'invoicing.view');
    }
}
