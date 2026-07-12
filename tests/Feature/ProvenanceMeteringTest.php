<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Provider as ProviderBase;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismCassette\CassetteProvider;
use Rushing\PrismCassette\Events\CassetteResolved;

// ── AC4: Event cannot be queued ──────────────────────────────────────────────

it('CassetteResolved does not implement ShouldQueue', function () {
    expect(CassetteResolved::class)->not->toImplement(ShouldQueue::class);
});

// ── AC1: Every record dispatches CassetteResolved with replayed=false ────────

it('record dispatches CassetteResolved with replayed=false and correct group', function () {
    config()->set('cassette.stores.file.path', tempProvenanceDir());

    $events = [];
    Event::listen(CassetteResolved::class, function (CassetteResolved $e) use (&$events) {
        $events[] = $e;
    });

    $manager = app(CassetteManager::class);
    $provider = new CassetteProvider(provenanceFakeDelegate('hello'), null, 'scope', 'openai');

    $manager->group('meter-record')->record()->play(fn () => $provider->text(provenanceTextRequest('q')));

    expect($events)->toHaveCount(1);
    $e = $events[0];
    expect($e->type)->toBe('text')
        ->and($e->replayed)->toBeFalse()
        ->and($e->group)->toBe('meter-record')
        ->and($e->provider)->toBe('openai')
        ->and($e->model)->toBe('gpt-4o')
        ->and($e->recordedAt)->not->toBeNull();
});

it('only one event is dispatched per interaction', function () {
    config()->set('cassette.stores.file.path', tempProvenanceDir());

    $count = 0;
    Event::listen(CassetteResolved::class, function () use (&$count) {
        $count++;
    });

    $manager = app(CassetteManager::class);
    $provider = new CassetteProvider(provenanceFakeDelegate('x'), null, 'scope', 'openai');

    $manager->group('one-event')->record()->play(fn () => $provider->text(provenanceTextRequest('q')));

    expect($count)->toBe(1);
});

// ── AC2: Replay dispatches CassetteResolved with replayed=true + recorded usage

it('replay dispatches CassetteResolved with replayed=true and usage from cassette', function () {
    $dir = tempProvenanceDir();
    config()->set('cassette.stores.file.path', $dir);

    $manager = app(CassetteManager::class);
    $provider = new CassetteProvider(provenanceFakeDelegate('reply', promptTokens: 7, completionTokens: 11), null, 'scope', 'openai');

    // Record first.
    $manager->group('meter-replay')->record()->play(fn () => $provider->text(provenanceTextRequest('q')));

    $replayEvents = [];
    Event::listen(CassetteResolved::class, function (CassetteResolved $e) use (&$replayEvents) {
        $replayEvents[] = $e;
    });

    // Replay.
    $manager->group('meter-replay')->replay()->play(fn () => $provider->text(provenanceTextRequest('q')));

    expect($replayEvents)->toHaveCount(1);
    $e = $replayEvents[0];
    expect($e->replayed)->toBeTrue()
        ->and($e->group)->toBe('meter-replay')
        ->and($e->usage->promptTokens)->toBe(7)
        ->and($e->usage->completionTokens)->toBe(11)
        ->and($e->recordedAt)->not->toBeNull();
});

it('replay event carries recordedAt from the cassette, not the current time', function () {
    $dir = tempProvenanceDir();
    config()->set('cassette.stores.file.path', $dir);

    $manager = app(CassetteManager::class);
    $provider = new CassetteProvider(provenanceFakeDelegate('x'), null, 'scope', 'openai');

    $before = now()->toIso8601String();
    $manager->group('ts-check')->record()->play(fn () => $provider->text(provenanceTextRequest('q')));

    // Read the recorded_at directly from the cassette file.
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    $cassetteFile = null;
    foreach ($files as $f) {
        if ($f->getExtension() === 'json') {
            $cassetteFile = $f->getPathname();
            break;
        }
    }
    $recordedAt = json_decode(file_get_contents($cassetteFile), true)['recorded_at'];

    $replayEvent = null;
    Event::listen(CassetteResolved::class, function (CassetteResolved $e) use (&$replayEvent) {
        $replayEvent = $e;
    });

    $manager->group('ts-check')->replay()->play(fn () => $provider->text(provenanceTextRequest('q')));

    expect($replayEvent->recordedAt)->toBe($recordedAt);
});

// ── AC3: onResolved closure arrives on the event ─────────────────────────────

it('onResolved closure set on scope arrives on the dispatched event', function () {
    config()->set('cassette.stores.file.path', tempProvenanceDir());

    $captured = null;
    Event::listen(CassetteResolved::class, function (CassetteResolved $e) use (&$captured) {
        $captured = $e->onResolved;
    });

    $manager = app(CassetteManager::class);
    $provider = new CassetteProvider(provenanceFakeDelegate('hi'), null, 'scope', 'openai');

    $sentinel = fn () => 'sentinel';
    $manager->group('closure-test')
        ->record()
        ->onResolved($sentinel)
        ->play(fn () => $provider->text(provenanceTextRequest('q')));

    expect($captured)->toBe($sentinel);
});

it('onResolved closure is null on the event when not set', function () {
    config()->set('cassette.stores.file.path', tempProvenanceDir());

    $captured = 'not-set';
    Event::listen(CassetteResolved::class, function (CassetteResolved $e) use (&$captured) {
        $captured = $e->onResolved;
    });

    $manager = app(CassetteManager::class);
    $provider = new CassetteProvider(provenanceFakeDelegate('x'), null, 'scope', 'openai');

    $manager->group('no-closure')->record()->play(fn () => $provider->text(provenanceTextRequest('q')));

    expect($captured)->toBeNull();
});

// ── AC5: Package emits only — no in-package listener ────────────────────────

it('no listener for CassetteResolved is registered by the package itself', function () {
    $listeners = Event::getListeners(CassetteResolved::class);
    expect($listeners)->toBeEmpty();
});

// ── storeName propagates correctly ───────────────────────────────────────────

it('event carries the correct storeName from the scope', function () {
    $dir = tempProvenanceDir();
    config()->set('cassette.stores.named_store', ['driver' => 'file', 'path' => $dir]);

    $captured = null;
    Event::listen(CassetteResolved::class, function (CassetteResolved $e) use (&$captured) {
        $captured = $e->storeName;
    });

    $manager = app(CassetteManager::class);
    $provider = new CassetteProvider(provenanceFakeDelegate('y'), null, 'scope', 'openai');

    $manager->group('store-name-test', 'named_store')->record()->play(fn () => $provider->text(provenanceTextRequest('q')));

    expect($captured)->toBe('named_store');
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function tempProvenanceDir(): string
{
    return sys_get_temp_dir().'/cassette-provenance-'.uniqid();
}

function provenanceTextRequest(string $prompt = 'Hello'): TextRequest
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

function provenanceFakeDelegate(string $text, int $promptTokens = 3, int $completionTokens = 5): ProviderBase
{
    return new class($text, $promptTokens, $completionTokens) extends ProviderBase
    {
        public function __construct(
            private string $text,
            private int $promptTokens,
            private int $completionTokens,
        ) {}

        #[Override]
        public function text(TextRequest $request): TextResponse
        {
            return new TextResponse(
                steps: new Collection,
                text: $this->text,
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage($this->promptTokens, $this->completionTokens),
                meta: new Meta('fake-id', 'gpt-4o'),
                messages: new Collection,
            );
        }
    };
}
