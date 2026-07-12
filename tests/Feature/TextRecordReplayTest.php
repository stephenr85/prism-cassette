<?php

use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Provider as ProviderBase;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteProvider;
use Rushing\PrismCassette\Drivers\FileCassetteStore;
use Rushing\PrismCassette\Exceptions\CassetteMissException;
use Rushing\PrismCassette\Support\CassetteKey;

// ── Unit: text record/replay ───────────────────────────────────────────────

it('records a text response and replays it without calling the delegate', function () {
    $store = new FileCassetteStore(tempTextDir());
    $delegate = textSpyDelegate();

    $recorder = new CassetteProvider(
        delegate: fakeTextDelegate('Hello world'),
        store: $store,
        mode: 'record',
        providerName: 'openai',
    );
    $recorder->text(textRequest('Tell me a joke'));

    $player = new CassetteProvider(
        delegate: $delegate,
        store: $store,
        mode: 'replay',
        providerName: 'openai',
    );
    $response = $player->text(textRequest('Tell me a joke'));

    expect($delegate->wasCalled)->toBeFalse()
        ->and($response->text)->toBe('Hello world');
});

it('throws CassetteMissException on text replay miss', function () {
    $store = new FileCassetteStore(tempTextDir());
    $player = new CassetteProvider(textSpyDelegate(), $store, 'replay', 'openai');

    $player->text(textRequest('no cassette here'));
})->throws(CassetteMissException::class, 'Cassette miss in replay mode');

it('cassette contains both request and response fields', function () {
    $dir = tempTextDir();
    $store = new FileCassetteStore($dir);

    $recorder = new CassetteProvider(fakeTextDelegate('The answer'), $store, 'record', 'openai');
    $recorder->text(textRequest('What is 2+2?'));

    $files = glob($dir.'/**/**/*.json') ?: [];
    expect($files)->toHaveCount(1);

    $cassette = json_decode(file_get_contents($files[0]), true);
    expect($cassette)->toHaveKey('request')
        ->and($cassette)->toHaveKey('response')
        ->and($cassette['request']['type'])->toBe('text')
        ->and($cassette['request']['provider'])->toBe('openai')
        ->and($cassette['response']['text'])->toBe('The answer')
        ->and($cassette['response']['finish_reason'])->toBe('stop')
        ->and($cassette['response']['usage'])->toHaveKey('prompt_tokens')
        ->and($cassette['response']['usage'])->toHaveKey('completion_tokens');
});

it('replays text with correct usage and meta', function () {
    $store = new FileCassetteStore(tempTextDir());

    $recorder = new CassetteProvider(fakeTextDelegate('42', promptTokens: 10, completionTokens: 3), $store, 'record', 'openai');
    $recorder->text(textRequest('What is 6 x 7?'));

    $player = new CassetteProvider(textSpyDelegate(), $store, 'replay', 'openai');
    $response = $player->text(textRequest('What is 6 x 7?'));

    expect($response->text)->toBe('42')
        ->and($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(3)
        ->and($response->meta->model)->toBe('gpt-4o');
});

it('records once and replays on subsequent identical calls', function () {
    $store = new FileCassetteStore(tempTextDir());
    $callCount = 0;
    $counting = countingTextDelegate($callCount, 'Counted');

    $provider = new CassetteProvider($counting, $store, 'record', 'openai');

    $first = $provider->text(textRequest('hello'));
    $second = $provider->text(textRequest('hello'));

    expect($callCount)->toBe(1)
        ->and($first->text)->toBe('Counted')
        ->and($second->text)->toBe('Counted');
});

it('changing the prompt produces a different cassette key', function () {
    $store = new FileCassetteStore($dir = tempTextDir());
    $provider = new CassetteProvider(fakeTextDelegate('response'), $store, 'record', 'openai');

    $provider->text(textRequest('First prompt'));
    $provider->text(textRequest('Second prompt'));

    $files = glob($dir.'/**/**/*.json') ?: [];
    expect($files)->toHaveCount(2);
});

it('cosmetic whitespace reflow does not change the cassette key', function () {
    $resolver = new CassetteKey;
    $keyA = $resolver->forText(textRequest('Tell   me   about  dogs'));
    $keyB = $resolver->forText(textRequest('Tell me about dogs'));

    expect($keyA)->toBe($keyB);
});

it('changing temperature produces a different cassette key', function () {
    $resolver = new CassetteKey;
    $reqHot = textRequest('hello', temperature: 0.9);
    $reqCold = textRequest('hello', temperature: 0.1);

    expect($resolver->forText($reqHot))->not->toBe($resolver->forText($reqCold));
});

it('changing system prompt produces a different cassette key', function () {
    $resolver = new CassetteKey;
    $keyA = $resolver->forText(textRequest('hello', systemPrompt: 'Be helpful'));
    $keyB = $resolver->forText(textRequest('hello', systemPrompt: 'Be concise'));

    expect($keyA)->not->toBe($keyB);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function tempTextDir(): string
{
    return sys_get_temp_dir().'/cassette-text-'.uniqid();
}

function textRequest(
    string $prompt = 'Hello',
    ?float $temperature = null,
    ?string $systemPrompt = null,
): TextRequest {
    return new TextRequest(
        model: 'gpt-4o',
        providerKey: 'openai',
        systemPrompts: $systemPrompt ? [new SystemMessage($systemPrompt)] : [],
        prompt: null,
        messages: [new UserMessage($prompt)],
        maxSteps: 1,
        maxTokens: null,
        temperature: $temperature,
        topP: null,
        tools: [],
        clientOptions: [],
        clientRetry: [0],
        toolChoice: null,
    );
}

function fakeTextDelegate(string $text, int $promptTokens = 5, int $completionTokens = 2): ProviderBase
{
    return new class($text, $promptTokens, $completionTokens) extends ProviderBase
    {
        public function __construct(
            private string $text,
            private int $promptTokens,
            private int $completionTokens,
        ) {}

        #[Override]
        public function text(Request $request): TextResponse
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

function textSpyDelegate(): ProviderBase
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

function countingTextDelegate(int &$count, string $text): ProviderBase
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
