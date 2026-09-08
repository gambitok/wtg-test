<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Always isolate feature tests from the development database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $app['config']->set(
            'database.connections.mysql.database',
            'housing_offers_testing',
        );

        return $app;
    }
}
