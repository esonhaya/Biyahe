<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\DatabaseTestSafety;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // This runs after configuration is loaded but before Laravel applies
        // RefreshDatabase or DatabaseMigrations test traits.
        DatabaseTestSafety::assertSafeDefaultConnection($app['config']->get('database'));

        return $app;
    }
}
