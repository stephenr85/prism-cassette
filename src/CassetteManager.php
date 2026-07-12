<?php

namespace Rushing\PrismCassette;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Rushing\PrismCassette\Contracts\CassetteStore;
use Rushing\PrismCassette\Contracts\NamingStrategy;
use Rushing\PrismCassette\Drivers\FileCassetteStore;
use Rushing\PrismCassette\Drivers\SqliteCassetteStore;
use Rushing\PrismCassette\Support\ContentAddressedStrategy;

class CassetteManager
{
    /** @var array<string, CassetteStore> */
    protected array $resolved = [];

    public function __construct(protected Application $app) {}

    public function store(?string $name = null): CassetteStore
    {
        $name ??= config('cassette.default', 'file');

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        return $this->resolved[$name] = $this->build($name);
    }

    public function group(string $group, ?string $store = null): CassetteScope
    {
        return new CassetteScope($this, $group, $store);
    }

    public function resolveMode(?string $storeName = null): string
    {
        $storeName ??= config('cassette.default', 'file');
        $storeConfig = config("cassette.stores.{$storeName}", []);

        if (isset($storeConfig['mode'])) {
            return $storeConfig['mode'];
        }

        if ($this->app->runningUnitTests()) {
            return 'replay';
        }

        if ($this->app->environment('production')) {
            return 'passthrough';
        }

        return config('cassette.mode', 'record');
    }

    public function namingStrategy(string $storeName): NamingStrategy
    {
        $storeConfig = config("cassette.stores.{$storeName}", []);
        $strategyClass = $storeConfig['naming_strategy'] ?? ContentAddressedStrategy::class;

        return $this->app->make($strategyClass);
    }

    protected function build(string $name): CassetteStore
    {
        $config = config("cassette.stores.{$name}");

        if (! $config) {
            throw new InvalidArgumentException("Cassette store [{$name}] is not defined.");
        }

        return match ($config['driver']) {
            'file' => new FileCassetteStore($config['path']),
            'sqlite' => new SqliteCassetteStore($config['database']),
            default => throw new InvalidArgumentException("Cassette driver [{$config['driver']}] is not supported."),
        };
    }
}
