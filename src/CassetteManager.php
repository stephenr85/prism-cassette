<?php

namespace Rushing\PrismCassette;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Rushing\PrismCassette\Contracts\CassetteSerializer;
use Rushing\PrismCassette\Contracts\CassetteStore;
use Rushing\PrismCassette\Contracts\NamingStrategy;
use Rushing\PrismCassette\Drivers\FileCassetteStore;
use Rushing\PrismCassette\Drivers\SqliteCassetteStore;
use Rushing\PrismCassette\Support\ContentAddressedStrategy;

class CassetteManager
{
    /** @var array<string, CassetteStore> */
    protected array $resolved = [];

    /** @var string[] */
    protected array $armedProviders = [];

    /** @var array<string, CassetteSerializer> capability => serializer (the extension seam). */
    protected array $serializers = [];

    public function __construct(protected Application $app) {}

    /**
     * Register a serializer that teaches cassette to tape a capability it doesn't natively know.
     *
     * This is the open-for-extension seam (see {@see CassetteSerializer}). Cassette self-registers
     * its own Prism-native audio serializers in {@see CassetteServiceProvider}; a downstream package
     * that owns a non-Prism capability (e.g. PrismPlus rerank) calls this from its service provider,
     * guarded by `class_exists()` so it never hard-depends on cassette. Last registration wins, so a
     * host can override a shipped serializer.
     */
    public function registerSerializer(string $capability, CassetteSerializer $serializer): void
    {
        $this->serializers[$capability] = $serializer;
    }

    public function serializer(string $capability): ?CassetteSerializer
    {
        return $this->serializers[$capability] ?? null;
    }

    public function markArmed(string $providerKey): void
    {
        $this->armedProviders[] = $providerKey;
    }

    public function isArmed(): bool
    {
        return $this->armedProviders !== [];
    }

    /** @return string[] */
    public function armedProviders(): array
    {
        return $this->armedProviders;
    }

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
