<?php

namespace Tests;

use App\Domains\Sri\Signing\Contracts\XmlSigner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\CreatesCompanyRoles;
use Tests\Support\PhpXadesBesTestSigner;

abstract class TestCase extends BaseTestCase
{
    use CreatesCompanyRoles;

    /** Without tools/xades-signer installed (a developer's machine) the suite signs with PHP; the container uses the jar. */
    protected function setUp(): void
    {
        parent::setUp();
        if (! is_file((string) config('sri.signer.jar'))) {
            $this->app->bind(XmlSigner::class, PhpXadesBesTestSigner::class);
        }
    }

    /**
     * Stops the suite before RefreshDatabase can wipe a real database when the
     * container environment overrides the values defined in phpunit.xml.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! $this->app->environment('testing') || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException("Las pruebas deben usar SQLite en memoria; se detectó [{$connection}:{$database}].");
        }

        return parent::setUpTraits();
    }
}
