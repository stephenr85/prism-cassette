<?php

use Prism\Prism\PrismManager;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismCassette\CassetteProvider;
use Rushing\PrismCassette\Exceptions\CassetteMissException;
use Rushing\PrismCassette\Tests\PassthroughTestCase;

uses(PassthroughTestCase::class);

// The footgun (issue 13): global CASSETTE_MODE=passthrough used to skip arming at
// boot, so every later explicit ->record()/->replay() scope was silently inert —
// real spend, nothing recorded. These tests boot under global passthrough
// (PassthroughTestCase) and pin the fixed contract.

it('arms the Prism providers even when the global mode is passthrough', function () {
    // The crux of incident 2: under passthrough boot the decorator never wrapped
    // the providers, so no scope could ever take effect.
    $provider = app(PrismManager::class)->resolve('openai');

    expect($provider)->toBeInstanceOf(CassetteProvider::class)
        ->and(app(CassetteManager::class)->isArmed())->toBeTrue()
        ->and(app(CassetteManager::class)->armedProviders())->toContain('openai');
});

it('explicit record()->play() under global passthrough records', function () {
    config()->set('cassette.stores.file.path', $dir = footgunTempDir());

    $manager = app(CassetteManager::class);
    $callCount = 0;
    $provider = new CassetteProvider(
        footgunCountingDelegate($callCount, 'taped'),
        null,
        'scope',
        'openai',
        manager: $manager,
    );

    $manager->group('footgun')->record()->play(fn () => $provider->text(footgunTextRequest('q')));

    expect($callCount)->toBe(1)
        ->and(footgunJsonFiles($dir))->toHaveCount(1);
});

it('explicit replay()->play() under global passthrough replays, and misses loudly', function () {
    config()->set('cassette.stores.file.path', $dir = footgunTempDir());

    $manager = app(CassetteManager::class);
    $callCount = 0;
    $provider = new CassetteProvider(
        footgunCountingDelegate($callCount, 'taped'),
        null,
        'scope',
        'openai',
        manager: $manager,
    );

    // Miss with nothing recorded: loud, never silent+live.
    expect(fn () => $manager->group('footgun')->replay()->play(
        fn () => $provider->text(footgunTextRequest('q'))
    ))->toThrow(CassetteMissException::class);

    // Record, then replay hits without touching the delegate again.
    $manager->group('footgun')->record()->play(fn () => $provider->text(footgunTextRequest('q')));
    $response = $manager->group('footgun')->replay()->play(fn () => $provider->text(footgunTextRequest('q')));

    expect($callCount)->toBe(1)
        ->and($response->text)->toBe('taped');
});

it('plain play() with no override resolving to passthrough runs the closure untouched', function () {
    // The normal dev-serving path (seeders under passthrough) must stay silent.
    // runningUnitTests() outranks the global config in resolveMode(), so pin the
    // effective mode via the store — the scope layer takes the same branch either way.
    config()->set('cassette.stores.file.mode', 'passthrough');
    config()->set('cassette.stores.file.path', $dir = footgunTempDir());

    $manager = app(CassetteManager::class);
    $callCount = 0;
    $provider = new CassetteProvider(
        footgunCountingDelegate($callCount, 'live'),
        null,
        'scope',
        'openai',
        manager: $manager,
    );

    $response = $manager->group('footgun')->play(fn () => $provider->text(footgunTextRequest('q')));

    expect($callCount)->toBe(1)
        ->and($response->text)->toBe('live')
        ->and(footgunJsonFiles($dir))->toHaveCount(0);
});

it('a per-store mode overrides global passthrough for plain play()', function () {
    // Per-store 'mode' config used to be unreachable under passthrough boot.
    config()->set('cassette.stores.file.mode', 'record');
    config()->set('cassette.stores.file.path', $dir = footgunTempDir());

    $manager = app(CassetteManager::class);
    $callCount = 0;
    $provider = new CassetteProvider(
        footgunCountingDelegate($callCount, 'stored'),
        null,
        'scope',
        'openai',
        manager: $manager,
    );

    $manager->group('footgun')->play(fn () => $provider->text(footgunTextRequest('q')));

    expect($callCount)->toBe(1)
        ->and(footgunJsonFiles($dir))->toHaveCount(1);
});

it('cassette:status reports the armed providers and resolved modes', function () {
    $this->artisan('cassette:status')
        ->expectsOutputToContain('passthrough')
        ->expectsOutputToContain('openai')
        ->assertSuccessful();
});
