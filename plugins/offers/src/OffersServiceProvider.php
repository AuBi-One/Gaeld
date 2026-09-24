<?php

namespace Plugins\Offers;

use App\Domains\Contacts\Services\ContactPanels;
use App\Domains\Invoicing\Services\InvoiceLineSources;
use App\Support\Plugins\PluginNavigation;
use Illuminate\Support\ServiceProvider;
use Plugins\Offers\Services\OfferContactPanel;
use Plugins\Offers\Services\OfferLineSource;

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

        // "Add line from offer" on the invoice form, and the link back from invoice lines.
        $this->app->make(InvoiceLineSources::class)->register(new OfferLineSource);

        // "Offers" section on the contact page.
        $this->app->make(ContactPanels::class)->register('offers', new OfferContactPanel);
    }
}
