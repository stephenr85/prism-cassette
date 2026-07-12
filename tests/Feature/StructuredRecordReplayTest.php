<?php

use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Providers\Provider as ProviderBase;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteProvider;
use Rushing\PrismCassette\Drivers\FileCassetteStore;
use Rushing\PrismCassette\Exceptions\CassetteMissException;
use Rushing\PrismCassette\Support\CassetteKey;

// ── Unit: structured record/replay ────────────────────────────────────────

it('records a structured response and replays it without calling the delegate', function () {
    $store = new FileCassetteStore(tempStructuredDir());
    $spy = structuredSpyDelegate();

    $recorder = new CassetteProvider(
        delegate: fakeStructuredDelegate(['name' => 'Alice', 'age' => 30]),
        store: $store,
        mode: 'record',
        providerName: 'openai',
    );
    $recorder->structured(structuredRequest('Who is the user?'));

    $player = new CassetteProvider(
        delegate: $spy,
        store: $store,
        mode: 'replay',
        providerName: 'openai',
    );
    $response = $player->structured(structuredRequest('Who is the user?'));

    expect($spy->wasCalled)->toBeFalse()
        ->and($response->structured)->toBe(['name' => 'Alice', 'age' => 30]);
});

it('throws CassetteMissException on structured replay miss', function () {
    $store = new FileCassetteStore(tempStructuredDir());
    $player = new CassetteProvider(structuredSpyDelegate(), $store, 'replay', 'openai');

    $player->structured(structuredRequest('no cassette'));
})->throws(CassetteMissException::class, 'Cassette miss in replay mode');

it('cassette file contains both request and response', function () {
    $dir = tempStructuredDir();
    $store = new FileCassetteStore($dir);

    $recorder = new CassetteProvider(fakeStructuredDelegate(['city' => 'Paris']), $store, 'record', 'openai');
    $recorder->structured(structuredRequest('What city is it?'));

    $files = glob($dir.'/**/**/*.json') ?: [];
    expect($files)->toHaveCount(1);

    $cassette = json_decode(file_get_contents($files[0]), true);
    expect($cassette)->toHaveKey('request')
        ->and($cassette)->toHaveKey('response')
        ->and($cassette['request']['type'])->toBe('structured')
        ->and($cassette['response']['structured'])->toBe(['city' => 'Paris'])
        ->and($cassette['response']['finish_reason'])->toBe('stop')
        ->and($cassette['response']['usage'])->toHaveKey('prompt_tokens');
});

it('structured payload replays losslessly', function () {
    $store = new FileCassetteStore(tempStructuredDir());
    $original = ['name' => 'Bob', 'score' => 99, 'tags' => ['a', 'b'], 'active' => true];

    $recorder = new CassetteProvider(fakeStructuredDelegate($original), $store, 'record', 'openai');
    $recorder->structured(structuredRequest('Get user data'));

    $player = new CassetteProvider(structuredSpyDelegate(), $store, 'replay', 'openai');
    $response = $player->structured(structuredRequest('Get user data'));

    expect($response->structured)->toBe($original);
});

it('records once and replays on subsequent identical calls', function () {
    $store = new FileCassetteStore(tempStructuredDir());
    $callCount = 0;
    $counting = countingStructuredDelegate($callCount, ['result' => 'ok']);

    $provider = new CassetteProvider($counting, $store, 'record', 'openai');
    $provider->structured(structuredRequest('same question'));
    $provider->structured(structuredRequest('same question'));

    expect($callCount)->toBe(1);
});

it('changing the prompt produces a different structured cassette key', function () {
    $store = new FileCassetteStore($dir = tempStructuredDir());
    $provider = new CassetteProvider(fakeStructuredDelegate(['x' => 1]), $store, 'record', 'openai');

    $provider->structured(structuredRequest('First'));
    $provider->structured(structuredRequest('Second'));

    $files = glob($dir.'/**/**/*.json') ?: [];
    expect($files)->toHaveCount(2);
});

it('changing the schema produces a different cassette key', function () {
    $resolver = new CassetteKey;
    $schemaA = new ObjectSchema('result', 'A result', [new StringSchema('name', 'Name')]);
    $schemaB = new ObjectSchema('result', 'A result', [new StringSchema('title', 'Title')]);

    $keyA = $resolver->forStructured(structuredRequest('hello', schema: $schemaA));
    $keyB = $resolver->forStructured(structuredRequest('hello', schema: $schemaB));

    expect($keyA)->not->toBe($keyB);
});

it('cosmetic whitespace reflow does not change the structured cassette key', function () {
    $resolver = new CassetteKey;
    $keyA = $resolver->forStructured(structuredRequest('Extract   user   info'));
    $keyB = $resolver->forStructured(structuredRequest('Extract user info'));

    expect($keyA)->toBe($keyB);
});

it('changing structured mode produces a different cassette key', function () {
    $resolver = new CassetteKey;
    $keyAuto = $resolver->forStructured(structuredRequest('hello', mode: StructuredMode::Auto));
    $keyJson = $resolver->forStructured(structuredRequest('hello', mode: StructuredMode::Json));

    expect($keyAuto)->not->toBe($keyJson);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function tempStructuredDir(): string
{
    return sys_get_temp_dir().'/cassette-structured-'.uniqid();
}

function defaultSchema(): ObjectSchema
{
    return new ObjectSchema(
        name: 'person',
        description: 'A person record',
        properties: [new StringSchema('name', 'The name')],
        requiredFields: ['name'],
    );
}

function structuredRequest(
    string $prompt = 'Extract the data',
    ?ObjectSchema $schema = null,
    StructuredMode $mode = StructuredMode::Auto,
): StructuredRequest {
    return new StructuredRequest(
        systemPrompts: [],
        model: 'gpt-4o',
        providerKey: 'openai',
        prompt: null,
        messages: [new UserMessage($prompt)],
        maxTokens: null,
        temperature: null,
        topP: null,
        clientOptions: [],
        clientRetry: [0],
        schema: $schema ?? defaultSchema(),
        mode: $mode,
        tools: [],
        toolChoice: null,
        maxSteps: 1,
    );
}

function fakeStructuredDelegate(array $structured, string $text = '{}'): ProviderBase
{
    return new class($structured, $text) extends ProviderBase
    {
        public function __construct(private array $structured, private string $text) {}

        #[Override]
        public function structured(Request $request): StructuredResponse
        {
            return new StructuredResponse(
                steps: new Collection,
                text: $this->text,
                structured: $this->structured,
                finishReason: FinishReason::Stop,
                usage: new Usage(5, 10),
                meta: new Meta('fake-id', 'gpt-4o'),
            );
        }
    };
}

function structuredSpyDelegate(): ProviderBase
{
    return new class extends ProviderBase
    {
        public bool $wasCalled = false;

        #[Override]
        public function structured(Request $request): StructuredResponse
        {
            $this->wasCalled = true;
            throw new RuntimeException('Delegate should not have been called in replay mode.');
        }
    };
}

function countingStructuredDelegate(int &$count, array $structured): ProviderBase
{
    return new class($count, $structured) extends ProviderBase
    {
        public function __construct(public int &$calls, private array $structured) {}

        #[Override]
        public function structured(Request $request): StructuredResponse
        {
            $this->calls++;

            return new StructuredResponse(
                steps: new Collection,
                text: '{}',
                structured: $this->structured,
                finishReason: FinishReason::Stop,
                usage: new Usage(1, 1),
                meta: new Meta('id', 'gpt-4o'),
            );
        }
    };
}
