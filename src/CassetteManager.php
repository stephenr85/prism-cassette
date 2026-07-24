<?php

namespace Rushing\PrismCassette;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use RuntimeException;
use Rushing\PrismCassette\Contracts\CassetteSerializer;
use Rushing\PrismCassette\Contracts\CassetteStore;
use Rushing\PrismCassette\Contracts\NamingStrategy;
use Rushing\PrismCassette\Contracts\RefinesEventMetering;
use Rushing\PrismCassette\Drivers\FileCassetteStore;
use Rushing\PrismCassette\Drivers\SqliteCassetteStore;
use Rushing\PrismCassette\Events\CassetteResolved;
use Rushing\PrismCassette\Exceptions\CassetteMissException;
use Rushing\PrismCassette\Support\CassetteId;
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

    /**
     * Record/replay/passthrough engine for ANY capability, taped through its registered serializer —
     * the public, provider-agnostic counterpart to {@see CassetteProvider::tapeAudio()}.
     *
     * Prism-native capabilities reach the store through the {@see CassetteProvider} decorator, which
     * only exists once Prism resolves a provider. A capability Prism has no slot for (PrismPlus rerank)
     * never constructs a CassetteProvider, so it drives this engine on the manager directly. Same mode
     * logic as the provider — an active {@see CassetteContextFrame} (pushed by group()->play()) wins;
     * otherwise the global mode from {@see resolveMode()} applies, so CASSETTE_MODE governs untouched.
     *
     * The type-specific work (key, (de)serialization, usage, preview) is deferred to the serializer;
     * a serializer that also implements {@see ReportsRawUsage} surfaces the provider's verbatim usage
     * array onto {@see CassetteResolved::$rawUsage}. Passthrough (or a global passthrough with no active
     * frame) runs live; a replay miss throws {@see CassetteMissException}; an armed capability with no
     * serializer runs live to record but fails loud on replay (never a silent empty response).
     *
     * @param  callable():object  $live  Runs the real call; invoked only on record/passthrough (never on a replay hit).
     */
    public function tape(string $capability, object $request, callable $live): object
    {
        $frame = CassetteContext::current();

        if ($frame !== null) {
            $store = $frame->store;
            $mode = $frame->mode;
            $group = $frame->group;
            $storeName = $frame->storeName;
            $strategy = $frame->namingStrategy;
            $onResolved = $frame->onResolved;
        } else {
            $mode = $this->resolveMode();

            if ($mode === 'passthrough') {
                return $live();
            }

            $storeName = config('cassette.default', 'file');
            $store = $this->store($storeName);
            $strategy = $this->namingStrategy($storeName);
            $group = 'default';
            $onResolved = null;
        }

        if ($mode === 'passthrough') {
            return $live();
        }

        $serializer = $this->serializer($capability);

        if ($serializer === null) {
            if ($mode === 'replay') {
                throw new RuntimeException(
                    "prism-cassette: no serializer registered for capability [{$capability}]; replay "
                    .'unavailable. Register one via CassetteManager::registerSerializer(), or use '
                    .'CASSETTE_MODE=passthrough.'
                );
            }

            return $live(); // record/passthrough-record without taping
        }

        $key = $strategy->key(new CassetteId($group, $serializer->key($request), $capability));

        if ($store->has($key)) {
            $data = $store->get($key);
            $response = $serializer->hydrate($data);
            $this->dispatchTaped($capability, $serializer, $request, $response, true, $mode, $group, $storeName, $key, $data['recorded_at'] ?? null, $onResolved);

            return $response;
        }

        if ($mode === 'replay') {
            throw CassetteMissException::make($capability, $serializer->provider($request), $serializer->model($request), $key, $serializer->preview($request));
        }

        $response = $live();
        $recordedAt = now()->toIso8601String();
        $store->put($key, $serializer->serialize($request, $response, $recordedAt));
        $this->dispatchTaped($capability, $serializer, $request, $response, false, $mode, $group, $storeName, $key, $recordedAt, $onResolved);

        return $response;
    }

    /**
     * Build + fire {@see CassetteResolved} for a capability taped through {@see tape()}. Provider is
     * request-derived; a serializer that implements {@see RefinesEventMetering} additionally refines
     * the event from the response — the RESOLVED model (vs. the request's possibly-null model, which
     * still drives the key) and the raw vendor usage array.
     */
    private function dispatchTaped(
        string $capability,
        CassetteSerializer $serializer,
        object $request,
        object $response,
        bool $replayed,
        string $mode,
        string $group,
        string $storeName,
        string $key,
        ?string $recordedAt,
        ?\Closure $onResolved,
    ): void {
        $refines = $serializer instanceof RefinesEventMetering ? $serializer : null;

        event(new CassetteResolved(
            type: $capability,
            provider: $serializer->provider($request),
            model: $refines?->resolvedModel($response) ?? $serializer->model($request),
            usage: $serializer->usage($response),
            replayed: $replayed,
            mode: $mode,
            group: $group,
            storeName: $storeName,
            key: $key,
            recordedAt: $recordedAt,
            onResolved: $onResolved,
            rawUsage: $refines?->rawUsage($response),
        ));
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
