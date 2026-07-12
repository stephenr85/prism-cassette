<?php

use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Provider as ProviderBase;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteContext;
use Rushing\PrismCassette\CassetteContextFrame;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismCassette\CassetteProvider;
use Rushing\PrismCassette\Contracts\NamingStrategy;
use Rushing\PrismCassette\Drivers\FileCassetteStore;
use Rushing\PrismCassette\Exceptions\CassetteMissException;
use Rushing\PrismCassette\Support\CassetteId;
use Rushing\PrismCassette\Support\ContentAddressedStrategy;

// ── AC1: global-mode behaviour (manager injected, as the service provider does) ────────

it('call outside scope passes through to the live delegate when no manager is injected', function () {
    // Direct construction without a manager preserves the old passthrough behaviour,
    // keeping existing unit-test helpers valid.
    $callCount = 0;
    $delegate = countingActivationDelegate($callCount, 'live response');

    $provider = new CassetteProvider($delegate, null, 'scope', 'openai');

    $response = $provider->text(activationTextRequest('hello'));

    expect($callCount)->toBe(1)
        ->and($response->text)->toBe('live response');
});

it('call outside scope uses global replay mode when manager is injected', function () {
    config()->set('cassette.stores.file.path', tempActivationDir());
    // runningUnitTests() → resolveMode() returns 'replay'; no cassette on disk → miss.
    $provider = new CassetteProvider(
        activationFakeDelegate('live'),
        null,
        'scope',
        'openai',
        manager: app(CassetteManager::class),
    );

    expect(fn () => $provider->text(activationTextRequest('any')))->toThrow(CassetteMissException::class);
});

it('call outside scope passes through when global mode resolves to passthrough', function () {
    config()->set('cassette.stores.file.mode', 'passthrough');

    $callCount = 0;
    $delegate = countingActivationDelegate($callCount, 'live');
    $provider = new CassetteProvider(
        $delegate,
        null,
        'scope',
        'openai',
        manager: app(CassetteManager::class),
    );

    $response = $provider->text(activationTextRequest('hello'));

    expect($callCount)->toBe(1)
        ->and($response->text)->toBe('live');
});

it('scope overrides global replay mode to record, then global replay resumes outside scope', function () {
    $dir = tempActivationDir();
    config()->set('cassette.stores.file.path', $dir);

    $manager = app(CassetteManager::class);

    $callCount = 0;
    $delegate = countingActivationDelegate($callCount, 'scoped-response');
    $provider = new CassetteProvider($delegate, null, 'scope', 'openai', manager: $manager);

    // Global mode would replay (runningUnitTests), but scope forces record.
    $manager->group('override-group')->record()->play(fn () => $provider->text(activationTextRequest('q')));
    expect($callCount)->toBe(1);

    // Back to global replay — cassette now exists → replays without hitting delegate.
    $response = $manager->group('override-group')->play(fn () => $provider->text(activationTextRequest('q')));
    expect($callCount)->toBe(1)  // delegate not called again
        ->and($response->text)->toBe('scoped-response');
});

it('call inside scope is taped; call outside scope hits live delegate', function () {
    $dir = tempActivationDir();
    $store = new FileCassetteStore($dir);
    $strategy = new ContentAddressedStrategy;

    $callCount = 0;
    $delegate = countingActivationDelegate($callCount, 'recorded');
    $provider = new CassetteProvider($delegate, null, 'scope', 'openai');

    // Outside scope: live delegate called.
    $provider->text(activationTextRequest('hello'));
    expect($callCount)->toBe(1);

    // Inside record scope: delegate called once more, cassette written.
    $frame = new CassetteContextFrame('test-group', $store, 'record', $strategy);
    CassetteContext::push($frame);
    try {
        $provider->text(activationTextRequest('hello'));
    } finally {
        CassetteContext::pop();
    }
    expect($callCount)->toBe(2);

    // Inside replay scope: cassette hit, delegate NOT called.
    $replayFrame = new CassetteContextFrame('test-group', $store, 'replay', $strategy);
    CassetteContext::push($replayFrame);
    try {
        $response = $provider->text(activationTextRequest('hello'));
    } finally {
        CassetteContext::pop();
    }
    expect($callCount)->toBe(2)
        ->and($response->text)->toBe('recorded');

    // After scope: live delegate called again.
    $provider->text(activationTextRequest('hello'));
    expect($callCount)->toBe(3);
});

// ── AC2: Cassette::group()->play() scope ──────────────────────────────────

it('Cassette::group()->play() tapes the call and disarms after the closure', function () {
    config()->set('cassette.stores.file.path', tempActivationDir());
    config()->set('cassette.mode', 'record');

    $manager = app(CassetteManager::class);

    $callCount = 0;
    $delegate = countingActivationDelegate($callCount, 'scoped');
    $provider = new CassetteProvider($delegate, null, 'scope', 'openai');

    // Record via scope.
    $manager->group('ac2-group')->record()->play(function () use ($provider) {
        $provider->text(activationTextRequest('question'));
    });
    expect($callCount)->toBe(1);

    // Replay via scope — delegate not called.
    $manager->group('ac2-group')->replay()->play(function () use ($provider, &$response) {
        $response = $provider->text(activationTextRequest('question'));
    });
    expect($callCount)->toBe(1)
        ->and($response->text)->toBe('scoped');

    // Outside scope — delegate called directly.
    $provider->text(activationTextRequest('question'));
    expect($callCount)->toBe(2);
});

it('scope disarms even when the closure throws', function () {
    config()->set('cassette.stores.file.path', tempActivationDir());

    $manager = app(CassetteManager::class);
    $provider = new CassetteProvider(activationFakeDelegate('x'), null, 'scope', 'openai');

    expect(fn () => $manager->group('exc-group')->record()->play(function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    // After exception, context stack must be clear.
    expect(CassetteContext::current())->toBeNull();

    // Without an injected manager the provider falls back to passthrough (no global mode).
    $callCount = 0;
    $counting = countingActivationDelegate($callCount, 'fallback');
    $freshProvider = new CassetteProvider($counting, null, 'scope', 'openai');
    $freshProvider->text(activationTextRequest('hello'));
    expect($callCount)->toBe(1);
});

// ── AC3: Two named stores independently targeted ──────────────────────────

it('two named stores are independently targeted', function () {
    $dirA = tempActivationDir();
    $dirB = tempActivationDir();

    config()->set('cassette.stores.store_a', ['driver' => 'file', 'path' => $dirA]);
    config()->set('cassette.stores.store_b', ['driver' => 'file', 'path' => $dirB]);

    $manager = app(CassetteManager::class);

    $delegate = activationFakeDelegate('stored');
    $provider = new CassetteProvider($delegate, null, 'scope', 'openai');

    // Record into store_a.
    $manager->group('grp', 'store_a')->record()->play(fn () => $provider->text(activationTextRequest('q')));
    // Record into store_b.
    $manager->group('grp', 'store_b')->record()->play(fn () => $provider->text(activationTextRequest('q')));

    expect(allJsonFiles($dirA))->toHaveCount(1)
        ->and(allJsonFiles($dirB))->toHaveCount(1);

    // Replay from store_a — spy not called.
    $spy = activationSpyDelegate();
    $replayProvider = new CassetteProvider($spy, null, 'scope', 'openai');
    $manager->group('grp', 'store_a')->replay()->play(fn () => $replayProvider->text(activationTextRequest('q')));
    expect($spy->wasCalled)->toBeFalse();
});

it('replay miss in one store does not affect the other', function () {
    $dirA = tempActivationDir();
    $dirB = tempActivationDir();

    config()->set('cassette.stores.store_a', ['driver' => 'file', 'path' => $dirA]);
    config()->set('cassette.stores.store_b', ['driver' => 'file', 'path' => $dirB]);

    $manager = app(CassetteManager::class);
    $provider = new CassetteProvider(activationFakeDelegate('ok'), null, 'scope', 'openai');

    // Only record into store_a.
    $manager->group('g', 'store_a')->record()->play(fn () => $provider->text(activationTextRequest('q')));

    // store_b replay should miss.
    expect(fn () => $manager->group('g', 'store_b')->replay()->play(fn () => $provider->text(activationTextRequest('q'))))
        ->toThrow(CassetteMissException::class);
});

// ── AC4: Custom NamingStrategy changes file path without breaking replay ──

it('a custom naming strategy changes where cassettes are filed without breaking replay', function () {
    $dir = tempActivationDir();

    $custom = new class implements NamingStrategy
    {
        public function key(CassetteId $id): string
        {
            return 'custom-group/'.$id->type.'/'.$id->hash;
        }
    };

    $store = new FileCassetteStore($dir);

    // Record with the custom strategy.
    $recordFrame = new CassetteContextFrame('g', $store, 'record', $custom);
    CassetteContext::push($recordFrame);
    try {
        $provider = new CassetteProvider(activationFakeDelegate('custom-result'), null, 'scope', 'openai');
        $provider->text(activationTextRequest('what'));
    } finally {
        CassetteContext::pop();
    }

    // File must be under custom-group/text/…
    $found = allJsonFiles($dir.'/custom-group/text');
    expect($found)->toHaveCount(1);

    // Replay with the same custom strategy.
    $replayFrame = new CassetteContextFrame('g', $store, 'replay', $custom);
    CassetteContext::push($replayFrame);
    try {
        $spy = activationSpyDelegate();
        $replayProvider = new CassetteProvider($spy, null, 'scope', 'openai');
        $response = $replayProvider->text(activationTextRequest('what'));
    } finally {
        CassetteContext::pop();
    }

    expect($spy->wasCalled)->toBeFalse()
        ->and($response->text)->toBe('custom-result');
});

// ── AC5: Mode resolution ─────────────────────────────────────────────────

it('resolveMode returns replay when running unit tests', function () {
    // Testbench always has runningUnitTests() = true.
    $mode = app(CassetteManager::class)->resolveMode('file');
    expect($mode)->toBe('replay');
});

it('per-store mode key overrides environment-based default', function () {
    config()->set('cassette.stores.file.mode', 'record');

    $mode = app(CassetteManager::class)->resolveMode('file');
    expect($mode)->toBe('record');
});

it('explicit scope->record() override wins over environment default', function () {
    config()->set('cassette.stores.file.path', $dir = tempActivationDir());

    $manager = app(CassetteManager::class);
    // runningUnitTests() would default to 'replay', but we force record.
    $callCount = 0;
    $provider = new CassetteProvider(countingActivationDelegate($callCount, 'ok'), null, 'scope', 'openai');

    $manager->group('override-test')->record()->play(fn () => $provider->text(activationTextRequest('q')));
    expect($callCount)->toBe(1);  // delegate called = record mode was used, not replay
});

// ── Helpers ───────────────────────────────────────────────────────────────

function tempActivationDir(): string
{
    return sys_get_temp_dir().'/cassette-activation-'.uniqid();
}

function activationTextRequest(string $prompt = 'Hello'): TextRequest
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

function activationFakeDelegate(string $text): ProviderBase
{
    return new class($text) extends ProviderBase
    {
        public function __construct(private string $text) {}

        #[Override]
        public function text(Request $request): TextResponse
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

function activationSpyDelegate(): ProviderBase
{
    return new class extends ProviderBase
    {
        public bool $wasCalled = false;

        #[Override]
        public function text(Request $request): TextResponse
        {
            $this->wasCalled = true;
            throw new RuntimeException('Delegate should not have been called in replay mode.');
        }
    };
}

function countingActivationDelegate(int &$count, string $text): ProviderBase
{
    return new class($count, $text) extends ProviderBase
    {
        public function __construct(public int &$calls, private string $text) {}

        #[Override]
        public function text(Request $request): TextResponse
        {
            $this->calls++;

            return new TextResponse(
                steps: new Collection,
                text: $this->text,
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(1, 1),
                meta: new Meta('id', 'gpt-4o'),
                messages: new Collection,
            );
        }
    };
}

/** @return string[] */
function allJsonFiles(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $result = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'json') {
            $result[] = $file->getPathname();
        }
    }

    return $result;
}
