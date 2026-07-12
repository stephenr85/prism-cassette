<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Prism\Prism\Embeddings\Content;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Provider as ProviderBase;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismCassette\CassetteProvider;
use Rushing\PrismCassette\Contracts\CassetteStore;
use Rushing\PrismCassette\Drivers\FileCassetteStore;
use Rushing\PrismCassette\Drivers\SqliteCassetteStore;

// ── AC1: Round-trip text cassettes (file → SQLite → file) ───────────────────

it('round-trip export then import reproduces byte-identical text cassette payloads', function () {
    $dir1 = rtTempDir('source');
    $dir2 = rtTempDir('target');
    $sqliteDb = sys_get_temp_dir().'/cassette-rt-'.uniqid().'.sqlite';

    // Record two cassettes into the source file store.
    $fileStore = new FileCassetteStore($dir1);
    $provider = new CassetteProvider(rtTextDelegate('Hello'), $fileStore, 'record', 'openai');
    $provider->text(rtTextRequest('Hello'));

    $provider2 = new CassetteProvider(rtTextDelegate('World'), $fileStore, 'record', 'openai');
    $provider2->text(rtTextRequest('World'));

    // Export to SQLite.
    config()->set('cassette.stores.rt_source', ['driver' => 'file', 'path' => $dir1]);
    config()->set('cassette.stores.rt_sqlite', ['driver' => 'sqlite', 'database' => $sqliteDb]);

    $result = Artisan::call('cassette:export', ['source' => 'rt_source', 'target' => 'rt_sqlite']);
    expect($result)->toBe(0);

    // Import from SQLite to a fresh file store.
    config()->set('cassette.stores.rt_target', ['driver' => 'file', 'path' => $dir2]);
    $result = Artisan::call('cassette:import', ['source' => 'rt_sqlite', 'target' => 'rt_target']);
    expect($result)->toBe(0);

    // Compare every key: payloads must be identical.
    $sourceKeys = $fileStore->keys();
    $targetStore = new FileCassetteStore($dir2);

    expect($sourceKeys)->not->toBeEmpty();

    foreach ($sourceKeys as $key) {
        expect($targetStore->has($key))->toBeTrue("key [{$key}] missing from target");
        expect($targetStore->get($key))->toBe($fileStore->get($key));
    }
});

// ── AC2: Embedding vectors survive the round-trip exactly ───────────────────

it('embedding vectors survive the file→SQLite round-trip exactly', function () {
    $dir = rtTempDir('emb-source');
    $sqliteDb = sys_get_temp_dir().'/cassette-emb-'.uniqid().'.sqlite';

    $vector = [0.123456789, -0.987654321, 1.0, 0.0, -1.0];
    $fileStore = new FileCassetteStore($dir);
    $recorder = new CassetteProvider(rtEmbDelegate($vector), $fileStore, 'record', 'openai');
    $recorder->embeddings(rtEmbRequest('vector test'));

    // Transfer to SQLite.
    $sqliteStore = new SqliteCassetteStore($sqliteDb);
    foreach ($fileStore->keys() as $key) {
        $sqliteStore->put($key, $fileStore->get($key));
    }

    // Replay from SQLite: vectors must be within float32 tolerance.
    $player = new CassetteProvider(rtSpyDelegate(), $sqliteStore, 'replay', 'openai');
    $response = $player->embeddings(rtEmbRequest('vector test'));

    $replayed = $response->embeddings[0]->embedding;
    expect($replayed)->toHaveCount(count($vector));

    foreach ($vector as $i => $expected) {
        expect(abs($replayed[$i] - $expected))->toBeLessThan(1e-6);
    }
});

// ── AC3: cassette:verify exits non-zero when an expected cassette is missing ─

it('cassette:verify exits non-zero when a cassette is missing', function () {
    $dir = rtTempDir('verify');
    config()->set('cassette.stores.rt_verify', ['driver' => 'file', 'path' => $dir]);

    // Record one cassette.
    $fileStore = new FileCassetteStore($dir);
    $provider = new CassetteProvider(rtTextDelegate('hi'), $fileStore, 'record', 'openai');
    $provider->text(rtTextRequest('hi'));
    $existingKey = $fileStore->keys()[0];

    // Verify with a mix of existing and phantom key.
    $exit = Artisan::call('cassette:verify', [
        'store' => 'rt_verify',
        'keys' => [$existingKey, 'phantom-key-that-does-not-exist'],
    ]);

    expect($exit)->toBe(1);
});

it('cassette:verify exits zero when all expected cassettes are present', function () {
    $dir = rtTempDir('verify-ok');
    config()->set('cassette.stores.rt_verify_ok', ['driver' => 'file', 'path' => $dir]);

    $fileStore = new FileCassetteStore($dir);
    $provider = new CassetteProvider(rtTextDelegate('ok'), $fileStore, 'record', 'openai');
    $provider->text(rtTextRequest('ok'));
    $key = $fileStore->keys()[0];

    $exit = Artisan::call('cassette:verify', [
        'store' => 'rt_verify_ok',
        'keys' => [$key],
    ]);

    expect($exit)->toBe(0);
});

// ── AC4: cassette:prune removes only unreferenced cassettes ─────────────────

it('cassette:prune removes unreferenced cassettes and keeps referenced ones', function () {
    $dir = rtTempDir('prune');
    config()->set('cassette.stores.rt_prune', ['driver' => 'file', 'path' => $dir]);

    $fileStore = new FileCassetteStore($dir);
    $provider1 = new CassetteProvider(rtTextDelegate('keep'), $fileStore, 'record', 'openai');
    $provider1->text(rtTextRequest('keep this'));

    $provider2 = new CassetteProvider(rtTextDelegate('drop'), $fileStore, 'record', 'openai');
    $provider2->text(rtTextRequest('drop this'));

    $allKeys = $fileStore->keys();
    expect($allKeys)->toHaveCount(2);

    $keepKey = $allKeys[0];
    $dropKey = $allKeys[1];

    $exit = Artisan::call('cassette:prune', [
        'store' => 'rt_prune',
        'keys' => [$keepKey],
    ]);

    expect($exit)->toBe(0);

    $remaining = $fileStore->keys();
    expect($remaining)->toHaveCount(1)
        ->and($remaining[0])->toBe($keepKey)
        ->and($fileStore->has($dropKey))->toBeFalse();
});

// ── AC5: Driver selection is config-driven via named stores ─────────────────

it('SqliteCassetteStore implements the CassetteStore contract', function () {
    expect(SqliteCassetteStore::class)
        ->toImplement(CassetteStore::class);
});

it('CassetteManager resolves the sqlite driver from config', function () {
    $sqliteDb = sys_get_temp_dir().'/cassette-manager-'.uniqid().'.sqlite';
    config()->set('cassette.stores.rt_manager_sqlite', [
        'driver' => 'sqlite',
        'database' => $sqliteDb,
    ]);

    $manager = app(CassetteManager::class);
    $store = $manager->store('rt_manager_sqlite');

    expect($store)->toBeInstanceOf(SqliteCassetteStore::class);
});

// ── SQLite store unit: put/get/has/forget/keys ───────────────────────────────

it('SqliteCassetteStore put/get/has round-trip', function () {
    $store = new SqliteCassetteStore(sys_get_temp_dir().'/cassette-unit-'.uniqid().'.sqlite');
    $data = ['recorded_at' => '2025-01-01T00:00:00+00:00', 'response' => ['text' => 'hello']];

    expect($store->has('mykey'))->toBeFalse();

    $store->put('mykey', $data);

    expect($store->has('mykey'))->toBeTrue()
        ->and($store->get('mykey'))->toBe($data);
});

it('SqliteCassetteStore forget removes the entry', function () {
    $store = new SqliteCassetteStore(sys_get_temp_dir().'/cassette-forget-'.uniqid().'.sqlite');
    $store->put('toremove', ['x' => 1]);
    $store->forget('toremove');

    expect($store->has('toremove'))->toBeFalse()
        ->and($store->get('toremove'))->toBeNull();
});

it('SqliteCassetteStore keys returns all stored keys', function () {
    $store = new SqliteCassetteStore(sys_get_temp_dir().'/cassette-keys-'.uniqid().'.sqlite');
    $store->put('alpha', ['v' => 1]);
    $store->put('beta', ['v' => 2]);
    $store->put('gamma', ['v' => 3]);

    expect($store->keys())->toEqualCanonicalizing(['alpha', 'beta', 'gamma']);
});

it('FileCassetteStore keys returns all stored keys', function () {
    $dir = rtTempDir('file-keys');
    $store = new FileCassetteStore($dir);
    $store->put('group/text/ab/cd/abcdef', ['v' => 1]);
    $store->put('group/text/ef/01/ef0123', ['v' => 2]);

    expect($store->keys())->toEqualCanonicalizing([
        'group/text/ab/cd/abcdef',
        'group/text/ef/01/ef0123',
    ]);
});

it('FileCassetteStore forget removes the file', function () {
    $dir = rtTempDir('file-forget');
    $store = new FileCassetteStore($dir);
    $store->put('group/text/ab/cd/abcdef', ['v' => 1]);
    $store->forget('group/text/ab/cd/abcdef');

    expect($store->has('group/text/ab/cd/abcdef'))->toBeFalse();
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function rtTempDir(string $suffix): string
{
    return sys_get_temp_dir().'/cassette-rt-'.$suffix.'-'.uniqid();
}

function rtTextRequest(string $prompt): TextRequest
{
    return new TextRequest(
        model: 'gpt-4o',
        providerKey: 'openai',
        systemPrompts: [],
        prompt: null,
        messages: [new UserMessage($prompt)],
        maxSteps: 1,
        maxTokens: null,
        temperature: null,
        topP: null,
        tools: [],
        clientOptions: [],
        clientRetry: [0],
        toolChoice: null,
    );
}

function rtTextDelegate(string $text): ProviderBase
{
    return new class($text) extends ProviderBase
    {
        public function __construct(private string $text) {}

        #[Override]
        public function text(TextRequest $request): TextResponse
        {
            return new TextResponse(
                steps: new Collection,
                text: $this->text,
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(3, 5),
                meta: new Meta('fake-id', 'gpt-4o'),
                messages: new Collection,
            );
        }
    };
}

function rtEmbRequest(string $input): Request
{
    return new Request(
        model: 'text-embedding-3-small',
        providerKey: 'openai',
        clientOptions: [],
        clientRetry: [],
        providerOptions: [],
        contents: [
            new Content(
                parts: [new Text($input)]
            ),
        ],
    );
}

function rtEmbDelegate(array $vector): ProviderBase
{
    return new class($vector) extends ProviderBase
    {
        public function __construct(private array $vector) {}

        #[Override]
        public function embeddings(Request $request): Response
        {
            return new Response(
                embeddings: [new Embedding($this->vector)],
                usage: new EmbeddingsUsage(5),
                meta: new Meta('fake-id', $request->model()),
            );
        }
    };
}

function rtSpyDelegate(): ProviderBase
{
    return new class extends ProviderBase
    {
        #[Override]
        public function embeddings(Request $request): Response
        {
            throw new RuntimeException('Delegate should not be called during replay.');
        }
    };
}
