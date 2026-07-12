<?php

namespace Rushing\PrismCassette;

use Illuminate\Support\ServiceProvider;
use Prism\Prism\PrismManager;
use Rushing\PrismCassette\Commands\CassetteExportCommand;
use Rushing\PrismCassette\Commands\CassetteImportCommand;
use Rushing\PrismCassette\Commands\CassettePruneCommand;
use Rushing\PrismCassette\Commands\CassetteStatusCommand;
use Rushing\PrismCassette\Commands\CassetteVerifyCommand;
use Rushing\PrismCassette\Contracts\CassetteKeyResolver;
use Rushing\PrismCassette\Contracts\CassetteStore;
use Rushing\PrismCassette\Support\CassetteKey;

class CassetteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cassette.php', 'cassette');

        $this->app->singleton(CassetteManager::class);

        $this->app->bind(CassetteStore::class, fn ($app) => $app->make(CassetteManager::class)->store());

        $this->app->bind(
            CassetteKeyResolver::class,
            fn ($app) => $app->make(config('cassette.key_resolver', CassetteKey::class))
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/cassette.php' => config_path('cassette.php'),
        ], 'cassette-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CassetteExportCommand::class,
                CassetteImportCommand::class,
                CassetteVerifyCommand::class,
                CassettePruneCommand::class,
                CassetteStatusCommand::class,
            ]);
        }

        $this->armProviders();
    }

    protected function armProviders(): void
    {
        // Always arm — passthrough is a per-call decision inside CassetteProvider
        // (an unarmed decorator made explicit ->record()/->replay() scopes and
        // per-store modes silently inert, running live and recording nothing).
        $configured = config('cassette.providers', '*');
        $providerKeys = $configured === '*'
            ? $this->discoverProviderKeys()
            : array_map('trim', explode(',', (string) $configured));

        // Resolve the real provider BEFORE calling extend() to avoid circular dependency.
        $prismManager = $this->app->make(PrismManager::class);
        $keyResolver = $this->app->make(CassetteKeyResolver::class);
        $manager = $this->app->make(CassetteManager::class);

        foreach ($providerKeys as $providerKey) {
            try {
                $delegate = $prismManager->resolve($providerKey);
            } catch (\Throwable) {
                // Provider not configured (missing API key, etc.); skip.
                continue;
            }

            // Arm in 'scope' mode: the global CASSETTE_MODE drives all Prism calls by
            // default. A CassetteContextFrame (pushed by Cassette::group()->play()) overrides
            // the mode, store, and group label for a specific block — scopes are overrides,
            // not activation mechanisms.
            $prismManager->extend($providerKey, fn ($app, array $config) => new CassetteProvider(
                delegate: $delegate,
                store: null,
                mode: 'scope',
                providerName: $providerKey,
                keyResolver: $keyResolver,
                manager: $manager,
            ));

            $manager->markArmed($providerKey);
        }
    }

    /** @return string[] */
    protected function discoverProviderKeys(): array
    {
        return array_keys(config('prism.providers', []));
    }
}
