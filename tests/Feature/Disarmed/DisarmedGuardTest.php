<?php

use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismCassette\Exceptions\CassetteDisarmedException;
use Rushing\PrismCassette\Tests\DisarmedTestCase;

uses(DisarmedTestCase::class);

// Safety net: when boot armed no providers at all (misconfigured cassette.providers,
// every resolution failed, provider not registered), a scope that would tape must
// throw rather than push a frame no decorator will ever read. DisarmedTestCase
// boots with cassette.providers pointing at a key that cannot resolve.

it('boots with nothing armed', function () {
    expect(app(CassetteManager::class)->isArmed())->toBeFalse();
});

it('an explicit record() scope throws when nothing armed', function () {
    expect(fn () => app(CassetteManager::class)->group('g')->record()->play(fn () => 'never'))
        ->toThrow(CassetteDisarmedException::class);
});

it('a plain play() that resolves to a taping mode throws when nothing armed', function () {
    // runningUnitTests() resolves the mode to 'replay' — a taping frame nothing
    // would read. Loud, never silent+live.
    expect(fn () => app(CassetteManager::class)->group('g')->play(fn () => 'never'))
        ->toThrow(CassetteDisarmedException::class);
});

it('a plain play() that resolves to passthrough does not throw when nothing armed', function () {
    config()->set('cassette.stores.file.mode', 'passthrough');

    $result = app(CassetteManager::class)->group('g')->play(fn () => 'ran');

    expect($result)->toBe('ran');
});

it('the disarmed exception names the remedy', function () {
    try {
        app(CassetteManager::class)->group('g')->record()->play(fn () => 'never');
        $this->fail('Expected CassetteDisarmedException');
    } catch (CassetteDisarmedException $e) {
        expect($e->getMessage())->toContain('cassette:status')
            ->and($e->getMessage())->toContain('record');
    }
});

it('armCapability() arms a non-Prism capability so scopes no longer throw disarmed', function () {
    $manager = app(CassetteManager::class);

    // A capability Prism has no slot for (e.g. PrismPlus rerank) tapes through the manager directly,
    // so it needs no Prism provider armed — declaring it directly tape-able is enough.
    expect($manager->isArmed())->toBeFalse();

    $manager->armCapability('rerank');

    expect($manager->isArmed())->toBeTrue()
        ->and($manager->armedCapabilities())->toBe(['rerank']);

    // A record scope no longer throws — the guard sees the armed capability.
    config()->set('cassette.stores.file.path', sys_get_temp_dir().'/cassette-armcap-'.uniqid());

    $result = $manager->group('g')->record()->play(fn () => 'ran');

    expect($result)->toBe('ran');
});
