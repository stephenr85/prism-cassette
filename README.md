# rushing/prism-cassette

Deterministic record/replay for [Prism](https://github.com/prism-php/prism) — the Laravel LLM toolkit.

Make one real API call. Replay it forever. Zero cost, zero network, fully offline.

---

## The idea

Prism routes every LLM call through a provider. This package wraps those providers with a cassette decorator: on first run it calls the live API and writes the response to a JSON file; on every subsequent run it replays the file. The calling code sees no difference.

**Seeding a corpus offline**: wrap the seeder loop in `Cassette::group()->play()`, run once with `CASSETTE_MODE=record`, commit the cassette files, and every `composer fresh` after that costs nothing and needs no key.

**Deterministic tests**: cassettes committed alongside test fixtures make LLM-dependent tests instant and reproducible in CI without mocks or stubs.

**Production caching** (recipe, not enforced): arm a named store backed by a shared cache, wrap expensive repeated calls in a scope, get tenant-level LLM result deduplication.

---

## Installation

```bash
composer require rushing/prism-cassette
```

Publish the config:

```bash
php artisan vendor:publish --tag=cassette-config
```

---

## Configuration

`config/cassette.php`:

```php
return [
    // Global mode: 'replay' | 'record' | 'passthrough'
    // 'replay'      — miss throws CassetteMissException (safe default for CI)
    // 'record'      — miss calls the live provider, writes cassette, returns result
    // 'passthrough' — calls run live and untaped (per-call decision; scopes and
    //                 per-store modes still override it)
    'mode' => env('CASSETTE_MODE', 'replay'),

    // Which named store to use when no store is specified in the scope
    'default' => 'file',

    // Named stores — mirror the filesystems.disks pattern
    'stores' => [
        // git-tracked file store — the default source of truth
        'file' => [
            'driver' => 'file',
            'path'   => storage_path('cassettes'),
        ],

        // Single-file artifact for deployment or distribution
        // Export from 'file' via cassette:export
        // 'artifact' => [
        //     'driver'   => 'sqlite',
        //     'database' => storage_path('cassettes.sqlite'),
        // ],
    ],

    // Which Prism provider keys to arm. '*' = all registered providers.
    'providers' => env('CASSETTE_PROVIDERS', '*'),

    // FQCN of a CassetteKeyResolver implementation.
    // Swap to change how requests are hashed into cassette keys.
    'key_resolver' => \Rushing\PrismCassette\Support\CassetteKey::class,
];
```

### Modes

| Mode | Miss behaviour | Use when |
|---|---|---|
| `replay` | throws `CassetteMissException` | CI, production — no surprise live calls |
| `record` | calls live provider, writes cassette | first run, re-recording |
| `passthrough` | calls run live, untaped | load-testing, profiling, interactive dev |

Per-store mode overrides the global: add `'mode' => 'record'` to any store config.

The global mode is a **default, not a disarm switch**: an explicit scope override (`->record()`, `->replay()`) or a per-store `mode` always wins, including under global `passthrough`. Passthrough is decided per call inside the decorator, not at boot.

In tests, `runningUnitTests()` forces `replay` regardless of the global. To record inside a test, call `->record()` explicitly on the scope (see [Re-recording in tests](#re-recording-in-tests)).

---

## Arming providers

`CassetteServiceProvider` extends every Prism provider with the cassette decorator automatically on boot — **in every mode, including global `passthrough`**. Providers that aren't configured (missing API key, etc.) are skipped silently. The decorator operates in `'scope'` mode: it passes through transparently unless a cassette scope is active or a store/global mode says to tape.

The armed decorator's passthrough path costs ~5–6 µs per Prism call (measured against a bare delegate) — noise next to any live LLM call, which is why there is no boot-time disarm.

If boot armed **nothing** at all — no resolvable Prism providers *and* no capability declared tape-able (see [Taping a capability Prism has no slot for](#taping-a-capability-prism-has-no-slot-for)) — any scope that would record or replay throws `CassetteDisarmedException` instead of silently running live. Plain passthrough scopes never throw.

Inspect the arming state at any time:

```
php artisan cassette:status
```

which prints the global mode, the armed provider keys, any directly-armed non-Prism capabilities, and each store's driver, resolved mode, and location.

---

## Scopes

All cassette activity is opt-in. Wrap the code that makes Prism calls in a scope:

```php
use Rushing\PrismCassette\Facades\Cassette;

Cassette::group('my-group', 'my-store')->play(function () {
    // Any Prism text / structured / embeddings calls here are cassette-intercepted
    $response = Prism::text()->using('openai', 'gpt-4o-mini')->withPrompt('Hello')->asText();
});
```

`group` is a logical label stored in cassette metadata and used as the `operationType` in metering events. `store` is the named store key from config (omit to use the default).

### Chaining options

```php
Cassette::group('my-group', 'my-store')
    ->record()         // override mode for this scope
    ->onResolved(function (CassetteResolved $event, callable $default) {
        // custom metering — call $default() to run the built-in metering too
    })
    ->play(fn () => /* ... */);
```

### Nested scopes

Scopes stack. Inner scopes shadow outer ones for their duration.

---

## Named stores

Each named store is independent — its own path, its own mode, its own cassette files. Add as many as you need in `cassette.stores`.

### `file` driver

Git-tracked JSON files, one per request. The default and most common driver.

```php
'stores' => [
    'seeds'   => ['driver' => 'file', 'path' => database_path('seeders/data/corpus/cassettes')],
    'tests'   => ['driver' => 'file', 'path' => base_path('tests/fixtures/cassettes')],
],
```

### `sqlite` driver

Stores all cassettes in a single SQLite database. Useful for:

- **Deployment artifacts**: export a file store into one portable `.sqlite` file, ship it with the app, replay in production with no filesystem write access needed.
- **Tenant caches**: one `.sqlite` file per tenant, swapped in at runtime.

```php
'stores' => [
    'artifact' => [
        'driver'   => 'sqlite',
        'database' => storage_path('cassettes.sqlite'),
    ],
],
```

Use `cassette:export` to build the artifact from a file store (see [CLI commands](#cli-commands)).

---

## Cassette file format

Each cassette is a content-addressed JSON file named by a hash of the request:

```
{store_path}/{group}/{type}/{ab}/{cd}/{abcdef...hash}.json
```

```json
{
  "recorded_at": "2025-01-01T00:00:00+00:00",
  "request": {
    "type": "text",
    "provider": "openai",
    "model": "gpt-4o-mini",
    "messages": [{"role": "user", "content": "Hello"}]
  },
  "response": {
    "text": "Hi there!",
    "finish_reason": "stop",
    "usage": {"prompt_tokens": 10, "completion_tokens": 5}
  }
}
```

Cassette keys are cosmetically normalised (whitespace collapsed, temperature excluded by default) so minor prompt reformatting doesn't bust the cache.

---

## CLI commands

```bash
# Export all cassettes from a file store into a single SQLite artifact
php artisan cassette:export --store=file --output=storage/cassettes.sqlite

# Import a SQLite artifact back into a file store
php artisan cassette:import storage/cassettes.sqlite --store=file

# Verify all cassette files in a store are well-formed
php artisan cassette:verify --store=file

# Remove cassette files older than N days
php artisan cassette:prune --store=file --days=90
```

---

## The `CassetteResolved` event

After every cassette interaction (record or replay) the package fires a synchronous `CassetteResolved` event. The app listens; the package never bills or meters itself.

```php
use Rushing\PrismCassette\Events\CassetteResolved;

class MeterCassetteResolved
{
    public function handle(CassetteResolved $event): void
    {
        // $event->replayed === true  → replay, zero operator cost
        // $event->replayed === false → fresh call, meter normally
        // $event->onResolved        → call-site closure override (optional)
        // $event->group             → logical label / operation type
        // $event->usage             → Usage|EmbeddingsUsage
    }
}
```

Register it in your `EventServiceProvider`:

```php
use Rushing\PrismCassette\Events\CassetteResolved;

protected $listen = [
    CassetteResolved::class => [MeterCassetteResolved::class],
];
```

**Contract**: replayed calls cost the operator nothing. Your listener should zero out `cost_usd` (or skip the record entirely) when `$event->replayed === true`.

---

## Custom key resolver

Implement `CassetteKeyResolver` to change how requests are hashed:

```php
use Rushing\PrismCassette\Contracts\CassetteKeyResolver;

class MyKeyResolver implements CassetteKeyResolver
{
    public function forText(TextRequest $request): string { /* ... */ }
    public function forStructured(StructuredRequest $request): string { /* ... */ }
    public function forEmbeddings(EmbeddingsRequest $request): string { /* ... */ }
}
```

Then register it in config:

```php
'key_resolver' => MyKeyResolver::class,
```

---

## Taping a capability Prism has no slot for

Everything above taps **Prism-native** modalities (text, structured, embeddings, audio) through the
provider decorator. But some capabilities have no Prism slot at all — reranking, for example — so a
call never passes through a `CassetteProvider`. Cassette exposes a public engine so a downstream
package can tape its own capability through the same store, modes, and events. (Cassette's own audio
TTS/STT taping is built on this seam — `tts` / `stt` are just capabilities with registered
serializers.)

There are two moving parts:

**1. A `CassetteSerializer`** teaches cassette how to key, (de)serialize, and describe your capability's
request/response types — the type-specific work `tape()` defers to. "Who owns the response type owns
the serializer," so it ships from *your* package, not cassette:

```php
use Rushing\PrismCassette\Contracts\CassetteSerializer;

class RerankSerializer implements CassetteSerializer
{
    public function key(object $request): string      { /* hash of provider + query + documents + … */ }
    public function provider(object $request): string { /* 'voyageai' */ }
    public function model(object $request): string    { /* the requested model, or '' for default */ }
    public function preview(object $request): string  { /* a short human label for the cassette */ }

    public function serialize(object $request, object $response, string $recordedAt): array { /* → JSON */ }
    public function hydrate(array $data): object       { /* JSON → your typed response */ }
}
```

A capability whose usage isn't token-based (rerank bills search-units, not tokens) can additionally
implement `ReportsRawUsage` to surface the vendor's verbatim usage array onto `CassetteResolved`.

**2. `armCapability()`** declares the capability directly tape-able. A non-Prism capability taps
`CassetteManager::tape()` on the manager directly — not a provider decorator — so it needs **no Prism
provider armed** at boot. But the scope-disarmed guard still has to know something is tape-able, so you
declare it (this is what frees you from configuring a decoy Prism provider just to satisfy the guard):

```php
// In your package's service-provider boot():
public function boot(): void
{
    $manager = $this->app->make(\Rushing\PrismCassette\CassetteManager::class);
    $manager->registerSerializer('rerank', new RerankSerializer);
    $manager->armCapability('rerank');   // now record/replay scopes work with no Prism provider armed
}
```

**Then tape your calls** through the engine — usually from a small decorator around your driver, so
callers who hold the driver directly are taped too:

```php
$response = app(\Rushing\PrismCassette\CassetteManager::class)->tape(
    'rerank',                    // the capability key (must have a registered serializer)
    $subject,                    // the request object your serializer keys on
    fn () => $driver->rerank($request),   // the live call — invoked only on record/passthrough, never on a replay hit
);
```

Mode, store, scope, and the `CassetteResolved` event all behave exactly as they do for Prism-native
capabilities: a scope's `->record()`/`->replay()` wins, a replay miss throws `CassetteMissException`,
and `cassette:status` lists your armed capability alongside the Prism providers.

> Real-world example: [`rushing/laravel-prism-plus`](https://github.com/stephenr85/laravel-prism-plus)
> tapes its rerank capability exactly this way.

---

## Recipes

### Record-once corpus seeder

```php
use Rushing\PrismCassette\Facades\Cassette;

foreach ($sections as $section) {
    Cassette::group('corpus', 'corpus')->play(function () use ($section) {
        $embeddings = app(PrismEmbeddingsService::class)->embeddings($section->text, $model);
        // persist embeddings...
    });
}
```

Set `CASSETTE_MODE=record` for the first run. Commit the cassette files. All subsequent runs are offline.

### Per-tenant production caching

```php
// Dynamically select a per-tenant SQLite store
$store = "tenant-{$tenantId}";

Cassette::group("synthesis", $store)->play(fn () =>
    app(ChatService::class)->quickPrompt($prompt, $model)
);
```

Each tenant gets its own `.sqlite` file. Frequently-repeated synthesis calls (e.g. same document re-submitted) replay instantly with no API spend.

### Re-recording in tests

`runningUnitTests()` forces `replay` mode regardless of `CASSETTE_MODE`. To record inside a test:

```php
$scope = Cassette::group('my-group', 'my-store');
if (env('CASSETTE_MODE') === 'record') {
    $scope = $scope->record();
}
$scope->play(fn () => /* ... */);
```

Run with `CASSETTE_MODE=record OPENAI_API_KEY=... php artisan test` to record, commit the cassette files, then run normally to replay.

---

## Licence

MIT
