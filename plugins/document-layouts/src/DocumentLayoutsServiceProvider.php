<?php

namespace Plugins\DocumentLayouts;

use App\Support\Pdf\PdfLayouts;
use Illuminate\Support\ServiceProvider;
use Plugins\DocumentLayouts\Layouts\WordModelInvoiceLayout;
use Plugins\DocumentLayouts\Layouts\WordModelSalarySlipLayout;

class DocumentLayoutsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'document-layouts');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'document-layouts');

        $this->app->make(PdfLayouts::class)
            ->register(new WordModelInvoiceLayout)
            ->register(new WordModelSalarySlipLayout);
    }
}
