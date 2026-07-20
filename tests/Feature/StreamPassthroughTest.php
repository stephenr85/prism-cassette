<?php

use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Provider as ProviderBase;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteProvider;
use Rushing\PrismCassette\Drivers\FileCassetteStore;

// ── stream(): the override that keeps ->asStream() from throwing ────────────

it('proxies the delegate stream in passthrough mode', function () {
    $delegate = streamingDelegate(['Hel', 'lo ', 'world']);
    $provider = new CassetteProvider($delegate, null, 'passthrough', 'openai');

    $frames = iterator_to_array($provider->stream(textRequest('Say hello')));

    expect($frames)->toBe(['Hel', 'lo ', 'world'])
        ->and($delegate->streamCalled)->toBeTrue();
});

it('proxies the delegate stream in record mode (no stream cassette written yet)', function () {
    $dir = tempTextDir();
    $store = new FileCassetteStore($dir);
    $delegate = streamingDelegate(['a', 'b']);

    $provider = new CassetteProvider($delegate, $store, 'record', 'openai');
    $frames = iterator_to_array($provider->stream(textRequest('stream me')));

    expect($frames)->toBe(['a', 'b'])
        ->and($delegate->streamCalled)->toBeTrue()
        ->and(glob($dir.'/**/**/*.json') ?: [])->toBeEmpty();
});

it('fails loud on stream replay (not yet supported) instead of yielding empty', function () {
    $store = new FileCassetteStore(tempTextDir());
    $provider = new CassetteProvider(streamingDelegate(['x']), $store, 'replay', 'openai');

    // Generators are lazy — advance it to trigger the body.
    iterator_to_array($provider->stream(textRequest('replay me')));
})->throws(RuntimeException::class, 'streaming replay is not yet supported');

it('does not throw the base "unsupported" error once armed', function () {
    $provider = new CassetteProvider(streamingDelegate(['ok']), null, 'passthrough', 'openai');

    $frames = iterator_to_array($provider->stream(textRequest('hi')));

    expect($frames)->toBe(['ok']);
});

// ── helpers ────────────────────────────────────────────────────────────────

function streamingDelegate(array $frames): ProviderBase
{
    return new class($frames) extends ProviderBase
    {
        public bool $streamCalled = false;

        public function __construct(private array $frames) {}

        #[Override]
        public function stream(TextRequest $request): Generator
        {
            $this->streamCalled = true;

            yield from $this->frames;
        }

        #[Override]
        public function text(TextRequest $request): TextResponse
        {
            return new TextResponse(
                steps: new Collection,
                text: implode('', $this->frames),
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
