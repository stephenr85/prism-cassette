<?php

use Illuminate\Support\Facades\Event;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismCassette\Contracts\CassetteSerializer;
use Rushing\PrismCassette\Contracts\RefinesEventMetering;
use Rushing\PrismCassette\Events\CassetteResolved;
use Rushing\PrismCassette\Exceptions\CassetteMissException;

/*
 * Exercises the public capability engine CassetteManager::tape() directly — the seam a downstream
 * package (PrismPlus rerank) taps to record/replay a capability cassette doesn't natively know,
 * WITHOUT a Prism provider ever existing for the call. A fake 'demo' capability stands in for rerank.
 */

// ── A registered serializer records then replays, never touching the live callable ──────

it('records a capability call through tape() then replays it without running live', function () {
    config()->set('cassette.stores.file.path', tempTapeDir());

    $manager = app(CassetteManager::class);
    $manager->registerSerializer('demo', new DemoSerializer);

    $req = new DemoRequest('voyage', 'rerank-2.5', 'q');

    $recorded = $manager->group('tape-rt')->record()->play(
        fn () => $manager->tape('demo', $req, fn () => new DemoResponse('LIVE', ['total_tokens' => 9]))
    );

    $replayed = $manager->group('tape-rt')->replay()->play(
        fn () => $manager->tape('demo', $req, function () {
            throw new RuntimeException('live must not run on a replay hit');
        })
    );

    expect($recorded->label)->toBe('LIVE')
        ->and($replayed->label)->toBe('LIVE')
        ->and($replayed->usage)->toBe(['total_tokens' => 9]);
});

// ── Replay miss fails loud ──────────────────────────────────────────────────────────────

it('throws CassetteMissException on a tape() replay miss', function () {
    config()->set('cassette.stores.file.path', tempTapeDir());

    $manager = app(CassetteManager::class);
    $manager->registerSerializer('demo', new DemoSerializer);

    $manager->group('tape-miss')->replay()->play(
        fn () => $manager->tape('demo', new DemoRequest('voyage', 'rerank-2.5', 'nope'), fn () => new DemoResponse('x', []))
    );
})->throws(CassetteMissException::class);

// ── CassetteResolved carries provider/model/usage AND the raw vendor usage array ─────────

it('dispatches CassetteResolved with synthesized Usage and verbatim rawUsage', function () {
    config()->set('cassette.stores.file.path', tempTapeDir());

    $events = [];
    Event::listen(CassetteResolved::class, function (CassetteResolved $e) use (&$events) {
        $events[] = $e;
    });

    $manager = app(CassetteManager::class);
    $manager->registerSerializer('demo', new DemoSerializer);

    $manager->group('tape-meter')->record()->play(
        fn () => $manager->tape('demo', new DemoRequest('voyage', 'rerank-2.5', 'q'), fn () => new DemoResponse('L', ['total_tokens' => 42]))
    );

    expect($events)->toHaveCount(1);
    $e = $events[0];
    expect($e->type)->toBe('demo')
        ->and($e->provider)->toBe('voyage')
        // The event carries the RESOLVED model from the response, not the request's 'rerank-2.5'.
        ->and($e->model)->toBe('resolved-model-v9')
        ->and($e->replayed)->toBeFalse()
        ->and($e->usage->promptTokens)->toBe(42)
        ->and($e->rawUsage)->toBe(['total_tokens' => 42]);
});

// ── A serializer that does NOT report raw usage leaves rawUsage null ─────────────────────

it('leaves rawUsage null for a serializer that does not implement ReportsRawUsage', function () {
    config()->set('cassette.stores.file.path', tempTapeDir());

    $captured = 'unset';
    Event::listen(CassetteResolved::class, function (CassetteResolved $e) use (&$captured) {
        $captured = $e->rawUsage;
    });

    $manager = app(CassetteManager::class);
    $manager->registerSerializer('plain', new PlainDemoSerializer);

    $manager->group('tape-plain')->record()->play(
        fn () => $manager->tape('plain', new DemoRequest('voyage', 'm', 'q'), fn () => new DemoResponse('L', ['ignored' => 1]))
    );

    expect($captured)->toBeNull();
});

// ── Passthrough runs live and records nothing ────────────────────────────────────────────

it('runs live under passthrough without recording', function () {
    $dir = tempTapeDir();
    config()->set('cassette.stores.file.path', $dir);
    // A store-level mode overrides the runningUnitTests() default of 'replay'.
    config()->set('cassette.stores.file.mode', 'passthrough');

    $manager = app(CassetteManager::class);
    $manager->registerSerializer('demo', new DemoSerializer);

    // No active scope frame → falls through to the global mode (passthrough here).
    $out = $manager->tape('demo', new DemoRequest('voyage', 'm', 'q'), fn () => new DemoResponse('LIVE', []));

    expect($out->label)->toBe('LIVE')
        ->and(glob($dir.'/**/**/*.json') ?: [])->toHaveCount(0);
});

// ── Helpers ──────────────────────────────────────────────────────────────────────────────

function tempTapeDir(): string
{
    return sys_get_temp_dir().'/cassette-tape-'.uniqid();
}

class DemoRequest
{
    public function __construct(public string $provider, public string $model, public string $query) {}
}

class DemoResponse
{
    /** @param array<string, mixed> $usage */
    public function __construct(public string $label, public array $usage, public string $resolvedModel = 'resolved-model-v9') {}
}

class DemoSerializer implements CassetteSerializer, RefinesEventMetering
{
    public function key(object $request): string
    {
        /** @var DemoRequest $request */
        return hash('sha256', $request->provider.'|'.$request->model.'|'.$request->query);
    }

    public function provider(object $request): string
    {
        return $request->provider;
    }

    public function model(object $request): string
    {
        return $request->model;
    }

    public function preview(object $request): string
    {
        return $request->query;
    }

    public function serialize(object $request, object $response, string $recordedAt): array
    {
        /** @var DemoResponse $response */
        return [
            'recorded_at' => $recordedAt,
            'response' => ['label' => $response->label, 'usage' => $response->usage, 'model' => $response->resolvedModel],
        ];
    }

    public function hydrate(array $data): object
    {
        return new DemoResponse($data['response']['label'], $data['response']['usage'], $data['response']['model'] ?? 'resolved-model-v9');
    }

    public function usage(object $response): Usage
    {
        /** @var DemoResponse $response */
        return new Usage($response->usage['total_tokens'] ?? 0, 0);
    }

    public function resolvedModel(object $response): string
    {
        /** @var DemoResponse $response */
        return $response->resolvedModel;
    }

    public function rawUsage(object $response): array
    {
        /** @var DemoResponse $response */
        return $response->usage;
    }
}

class PlainDemoSerializer implements CassetteSerializer
{
    public function key(object $request): string
    {
        return hash('sha256', 'plain');
    }

    public function provider(object $request): string
    {
        return 'voyage';
    }

    public function model(object $request): string
    {
        return 'm';
    }

    public function preview(object $request): string
    {
        return 'p';
    }

    public function serialize(object $request, object $response, string $recordedAt): array
    {
        return ['recorded_at' => $recordedAt, 'response' => []];
    }

    public function hydrate(array $data): object
    {
        return new DemoResponse('L', []);
    }

    public function usage(object $response): Usage
    {
        return new Usage(0, 0);
    }
}
