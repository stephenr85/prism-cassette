<?php

namespace Rushing\PrismCassette\Tests;

/**
 * Boots the app so that no Prism provider arms: cassette.providers names a
 * key that does not resolve. Any scope that would tape must fail loudly
 * rather than push a frame no decorator will ever read.
 */
abstract class DisarmedTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cassette.providers', 'nonexistent-provider');
    }
}
