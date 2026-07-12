<?php

use Prism\Prism\Embeddings\Content;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider as ProviderBase;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Meta;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismCassette\CassetteProvider;
use Rushing\PrismCassette\CassetteServiceProvider;
use Rushing\PrismCassette\Drivers\FileCassetteStore;
use Rushing\PrismCassette\Exceptions\CassetteMissException;

// ── Unit: CassetteProvider ───────────────────────────────────────────────

it('replays from store and never calls the delegate', function () {
    $store = new FileCassetteStore(tempCassetteDir());
    $delegate = spyDelegate();

    // Seed a cassette manually via a record-mode provider.
    $recorder = new CassetteProvider(
        delegate: fakeDelegate([0.1, 0.2, 0.3]),
        store: $store,
        mode: 'record',
        providerName: 'openai',
    );
    $recorder->embeddings(embeddingsRequest());

    // Now replay — delegate must not be called.
    $player = new CassetteProvider(
        delegate: $delegate,
        store: $store,
        mode: 'replay',
        providerName: 'openai',
    );
    $response = $player->embeddings(embeddingsRequest());

    expect($delegate->wasCalled)->toBeFalse()
        ->and($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0]->embedding)->toHaveCount(3);
});

it('replays with float precision intact (packed binary round-trip)', function () {
    $store = new FileCassetteStore(tempCassetteDir());
    $vector = [0.123456789, -0.987654321, 1.0, 0.0, -1.0];

    $recorder = new CassetteProvider(fakeDelegate($vector), $store, 'record', 'openai');
    $recorder->embeddings(embeddingsRequest('precision test'));

    $player = new CassetteProvider(spyDelegate(), $store, 'replay', 'openai');
    $response = $player->embeddings(embeddingsRequest('precision test'));

    $replayed = $response->embeddings[0]->embedding;
    foreach ($vector as $i => $expected) {
        expect(abs($replayed[$i] - $expected))->toBeLessThan(1e-6);
    }
});

it('throws CassetteMissException on replay miss with key and input preview', function () {
    $store = new FileCassetteStore(tempCassetteDir());
    $player = new CassetteProvider(spyDelegate(), $store, 'replay', 'openai');

    $player->embeddings(embeddingsRequest('no cassette for this'));
})->throws(CassetteMissException::class, 'Cassette miss in replay mode');

it('identical input produces identical cassette key (content-addressed)', function () {
    $store = new FileCassetteStore($dir = tempCassetteDir());
    $provider = new CassetteProvider(fakeDelegate([0.5]), $store, 'record', 'openai');

    $provider->embeddings(embeddingsRequest('same text'));
    $provider->embeddings(embeddingsRequest('same text')); // second call: must be a hit

    // Only one file should exist in the store dir.
    $files = glob($dir.'/**/**/*.json') ?: [];
    expect($files)->toHaveCount(1);
});

it('records on miss then replays on the next call', function () {
    $store = new FileCassetteStore(tempCassetteDir());
    $callCount = 0;
    $delegate = new class($callCount) extends ProviderBase
    {
        public function __construct(public int &$calls) {}

        #[Override]
        public function embeddings(Request $request): Response
        {
            $this->calls++;

            return new Response(
                embeddings: [new Embedding([0.9, 0.8])],
                usage: new EmbeddingsUsage(10),
                meta: new Meta('id-1', 'text-embedding-3-small'),
            );
        }
    };

    $provider = new CassetteProvider($delegate, $store, 'record', 'openai');

    $first = $provider->embeddings(embeddingsRequest('hello'));
    $second = $provider->embeddings(embeddingsRequest('hello'));

    // Delegate only called once — second call was a replay hit.
    // Vectors are stored as float32 (packed binary), so exact float64 equality is not preserved;
    // use per-component tolerance.
    expect($delegate->calls)->toBe(1);
    foreach ($first->embeddings[0]->embedding as $i => $original) {
        expect(abs($second->embeddings[0]->embedding[$i] - $original))->toBeLessThan(1e-6);
    }
});

// ── Integration: Prism facade goes through CassetteProvider ─────────────

it('the Prism facade resolves through CassetteProvider when armed', function () {
    config(['cassette.mode' => 'replay', 'cassette.providers' => 'openai']);
    reboot();

    $provider = app(PrismManager::class)->resolve('openai');

    expect($provider)->toBeInstanceOf(CassetteProvider::class);
});

// ── Live record test (skipped unless API key present) ────────────────────

it('records a real embedding call and replays it', function () {
    if (! env('OPENAI_API_KEY')) {
        $this->markTestSkipped('OPENAI_API_KEY not set — record mode requires a live API key.');
    }

    $tmpDir = sys_get_temp_dir().'/cassette-live-'.uniqid();
    config(['cassette.mode' => 'record', 'cassette.providers' => 'openai', 'cassette.stores.file.path' => $tmpDir]);
    reboot();

    $input = 'Cassette live record test: '.date('Y-m-d H:i:s');

    $live = Prism::embeddings()
        ->using(Provider::OpenAI, 'text-embedding-3-small')
        ->fromInput($input)
        ->generate();

    expect($live->embeddings)->toHaveCount(1);

    // Flip to replay and re-arm.
    config(['cassette.mode' => 'replay']);
    reboot();

    $replayed = Prism::embeddings()
        ->using(Provider::OpenAI, 'text-embedding-3-small')
        ->fromInput($input)
        ->generate();

    expect($replayed->embeddings[0]->embedding)->toEqual($live->embeddings[0]->embedding);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function tempCassetteDir(): string
{
    return sys_get_temp_dir().'/cassette-test-'.uniqid();
}

function embeddingsRequest(string $input = 'The quick brown fox'): Request
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

function fakeDelegate(array $vector): ProviderBase
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

function spyDelegate(): ProviderBase
{
    return new class extends ProviderBase
    {
        public bool $wasCalled = false;

        #[Override]
        public function embeddings(Request $request): Response
        {
            $this->wasCalled = true;
            throw new RuntimeException('Delegate should not have been called in replay mode.');
        }
    };
}

function reboot(): void
{
    app()->forgetInstance(CassetteManager::class);
    $sp = new CassetteServiceProvider(app());
    $sp->register();
    $sp->boot();
}
