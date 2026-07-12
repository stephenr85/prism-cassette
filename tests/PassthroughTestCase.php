<?php

namespace Rushing\PrismCassette\Tests;

/**
 * Boots the app with the global cassette mode set to 'passthrough' — the
 * configuration that historically disarmed the decorator at boot and made
 * every explicit scope override silently inert.
 */
abstract class PassthroughTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cassette.mode', 'passthrough');
    }
}
