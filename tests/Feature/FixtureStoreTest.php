<?php

use Rushing\PrismCassette\Contracts\CassetteStore;
use Rushing\PrismCassette\Drivers\FileCassetteStore;
use Rushing\PrismCassette\Facades\Cassette;

// ── registerFixtureStore: package cassettes as a shareable, host-overridable store ──────────

it('registers a file store pointing at the package fixtures dir when none is configured', function () {
    config()->offsetUnset('cassette.stores.pkg_fixtures');

    Cassette::registerFixtureStore('pkg_fixtures', '/pkg/vendor/fixtures/cassettes');

    expect(config('cassette.stores.pkg_fixtures'))->toBe([
        'driver' => 'file',
        'path' => '/pkg/vendor/fixtures/cassettes',
    ]);
});

it('never clobbers a host-configured store — a published/overridden path wins', function () {
    // The host already pointed the store at its published copy of the cassettes.
    config(['cassette.stores.pkg_fixtures' => [
        'driver' => 'file',
        'path' => '/host/tests/fixtures/pkg/cassettes',
    ]]);

    Cassette::registerFixtureStore('pkg_fixtures', '/pkg/vendor/fixtures/cassettes');

    // Package default did NOT override the host — single source of truth stays the host's copy.
    expect(config('cassette.stores.pkg_fixtures.path'))->toBe('/host/tests/fixtures/pkg/cassettes');
});

it('resolves the registered store as a file-backed CassetteStore', function () {
    config()->offsetUnset('cassette.stores.pkg_fixtures');
    Cassette::registerFixtureStore('pkg_fixtures', __DIR__.'/../fixtures/cassettes');

    $store = Cassette::store('pkg_fixtures');

    expect($store)->toBeInstanceOf(CassetteStore::class)
        ->and($store)->toBeInstanceOf(FileCassetteStore::class);
});
