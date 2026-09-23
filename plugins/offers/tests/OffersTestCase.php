<?php

namespace Plugins\Offers\Tests;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
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
abstract class OffersTestCase extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected Contact $contact;

    protected ContactPerson $person;

    protected VatRate $vat;

    public function createApplication(): Application
    {
        self::setPluginsEnv('true');

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('of_settings')) {
            $this->artisan('migrate', ['--path' => 'plugins/offers/migrations', '--force' => true]);
        }

        $this->setUpOrganization();

        $this->contact = Contact::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'Salines Test SA',
            'address' => 'Route des Mines 1',
            'postal_code' => '1880',
            'city' => 'Bex',
            'country' => 'CH',
        ]);
        $this->person = $this->contact->contactPersons()->create([
            'first_name' => 'Marie',
            'last_name' => 'Exemple',
            'email' => 'marie@example.test',
            'is_primary' => true,
        ]);
        $this->vat = VatRate::factory()->create(['organization_id' => $this->org->id, 'rate' => 8.10]);
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
