<?php

namespace Plugins\DocumentLayouts\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Boots the application with plugins enabled (phpunit.xml disables them for the
 * core suite) and makes sure the offers tables (sender e-mail and phone) exist.
 */
abstract class DocumentLayoutsTestCase extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

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
