<?php

namespace Plugins\Offers\Tests;

require_once __DIR__.'/OffersTestCase.php';

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The plugin's (single, squashed) migration rolls back and forward with the
 * shape of the database review (F2/F5/F7/F8).
 */
class OffersSchemaTest extends OffersTestCase
{
    #[Test]
    public function the_plugin_migration_rolls_back_and_forward(): void
    {
        $this->artisan('migrate:reset', ['--path' => 'plugins/offers/migrations', '--force' => true])->assertSuccessful();
        foreach (['of_offer_lines', 'of_offers', 'of_settings', 'of_templates'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasTable('invoice_lines'));

        $this->artisan('migrate', ['--path' => 'plugins/offers/migrations', '--force' => true])->assertSuccessful();
        $this->assertContains('of_templates_default_per_org', array_column(Schema::getIndexes('of_templates'), 'name'));
        $this->assertContains(['created_by'], array_column(Schema::getForeignKeys('of_offers'), 'columns'));
        $this->assertContains('of_offers_organization_id_offer_date_index', array_column(Schema::getIndexes('of_offers'), 'name'));
        $total = collect(Schema::getColumns('of_offers'))->firstWhere('name', 'total');
        $this->assertSame('numeric(15,2)', $total['type'] ?? null);
    }
}
