<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
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
