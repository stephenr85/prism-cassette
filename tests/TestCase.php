<?php

namespace Rushing\PrismCassette\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Prism\Prism\PrismServiceProvider;
use Rushing\PrismCassette\CassetteServiceProvider;
use Rushing\PrismCassette\Facades\Cassette;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            CassetteServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Cassette' => Cassette::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Point the default file store at our fixture directory.
        $app['config']->set('cassette.stores.file.path', __DIR__.'/fixtures/cassettes');
    }
}
