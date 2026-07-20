<?php

namespace Rushing\PrismCassette;

use Generator;
use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\Contracts\CassetteKeyResolver;
use Rushing\PrismCassette\Contracts\CassetteStore;
use Rushing\PrismCassette\Events\CassetteResolved;
use Rushing\PrismCassette\Exceptions\CassetteMissException;
use Rushing\PrismCassette\Support\CassetteId;
use Rushing\PrismCassette\Support\CassetteKey;

class CassetteProvider extends Provider
{
    protected CassetteKeyResolver $keyResolver;

    public function __construct(
        protected Provider $delegate,
        protected ?CassetteStore $store,
        protected string $mode,
        protected string $providerName,
        ?CassetteKeyResolver $keyResolver = null,
        protected ?CassetteManager $manager = null,
    ) {
        $this->keyResolver = $keyResolver ?? new CassetteKey;
    }

    #[\Override]
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        [$store, $mode, $key] = $this->resolve('embeddings', $this->keyResolver->forEmbeddings($request));

        if ($store === null) {
            return $this->delegate->embeddings($request);
        }

        if ($store->has($key)) {
            $data = $store->get($key);
            $response = $this->hydrateEmbeddings($data);
            $this->dispatchResolved('embeddings', $request->provider(), $request->model(), $response->usage, true, $mode, $key, $data['recorded_at'] ?? null);

            return $response;
        }

        if ($mode === 'replay') {
            $inputPreview = substr(implode(' | ', $request->inputs()), 0, 80);
            throw CassetteMissException::make('embeddings', $this->providerName, $request->model(), $key, $inputPreview);
        }

        $response = $this->delegate->embeddings($request);
        $recordedAt = now()->toIso8601String();
        $store->put($key, $this->serializeEmbeddings($request, $response, $recordedAt));
        $this->dispatchResolved('embeddings', $request->provider(), $request->model(), $response->usage, false, $mode, $key, $recordedAt);

        return $response;
    }

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        [$store, $mode, $key] = $this->resolve('text', $this->keyResolver->forText($request));

        if ($store === null) {
            return $this->delegate->text($request);
        }

        if ($store->has($key)) {
            $data = $store->get($key);
            $response = $this->hydrateText($data);
            $this->dispatchResolved('text', $request->provider(), $request->model(), $response->usage, true, $mode, $key, $data['recorded_at'] ?? null);

            return $response;
        }

        if ($mode === 'replay') {
            $preview = substr(implode(' | ', array_map(fn ($m) => $m->content ?? '', $request->messages())), 0, 80);
            throw CassetteMissException::make('text', $this->providerName, $request->model(), $key, $preview);
        }

        $response = $this->delegate->text($request);
        $recordedAt = now()->toIso8601String();
        $store->put($key, $this->serializeText($request, $response, $recordedAt));
        $this->dispatchResolved('text', $request->provider(), $request->model(), $response->usage, false, $mode, $key, $recordedAt);

        return $response;
    }

    /**
     * Stream a text completion.
     *
     * The base Provider::stream() throws "unsupported", so without this override any
     * ->asStream() call against a cassette-armed provider dies before a single token —
     * which is exactly what broke Threads chat (empty assistant bubbles). We proxy the
     * live provider's stream for passthrough and record; stream-frame taping/replay is a
     * deliberate follow-up, so replay fails loud rather than masquerading as empty output.
     */
    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        [$store, $mode] = $this->resolve('text', $this->keyResolver->forText($request));

        // Passthrough (store === null): proxy the real provider's stream transparently.
        // Record (or scope-record): proxy live too — stream-frame serialization is a
        // scoped follow-up, so we stream through without writing a stream cassette
        // (non-streaming text/structured/embeddings still record as before).
        if ($store === null || $mode !== 'replay') {
            yield from $this->delegate->stream($request);

            return;
        }

        // Replay: streaming fixtures aren't implemented yet. Fail loud so a missing
        // stream cassette never silently degrades to an empty completion.
        throw new \RuntimeException(
            "prism-cassette: streaming replay is not yet supported (provider={$this->providerName}, "
            ."model={$request->model()}). Use CASSETTE_MODE=passthrough for streaming completions, "
            .'or record/replay the non-streaming text() path instead.'
        );
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        [$store, $mode, $key] = $this->resolve('structured', $this->keyResolver->forStructured($request));

        if ($store === null) {
            return $this->delegate->structured($request);
        }

        if ($store->has($key)) {
            $data = $store->get($key);
            $response = $this->hydrateStructured($data);
            $this->dispatchResolved('structured', $request->provider(), $request->model(), $response->usage, true, $mode, $key, $data['recorded_at'] ?? null);

            return $response;
        }

        if ($mode === 'replay') {
            throw CassetteMissException::make('structured', $this->providerName, $request->model(), $key, $request->schema()->name());
        }

        $response = $this->delegate->structured($request);
        $recordedAt = now()->toIso8601String();
        $store->put($key, $this->serializeStructured($request, $response, $recordedAt));
        $this->dispatchResolved('structured', $request->provider(), $request->model(), $response->usage, false, $mode, $key, $recordedAt);

        return $response;
    }

    /**
     * Resolve the (store, mode, lookup-key) triple for the current call.
     *
     * In scope mode: an active CassetteContextFrame (pushed by Cassette::group()->play())
     * overrides the global mode, store, and group for that block. Without an active frame
     * the call falls through to the global mode — so CASSETTE_MODE=replay intercepts all
     * Prism calls transparently, with no per-call-site wrapping required. Scopes are
     * overrides (group label, store, force-record), not activation mechanisms.
     *
     * In direct mode (record|replay): uses the constructor-provided store and mode.
     *
     * @return array{CassetteStore|null, string|null, string|null}
     */
    private function resolve(string $type, string $hash): array
    {
        if ($this->mode === 'passthrough') {
            return [null, null, null];
        }

        if ($this->mode === 'scope') {
            $frame = CassetteContext::current();

            if ($frame !== null) {
                $key = $frame->namingStrategy->key(new CassetteId($frame->group, $hash, $type));

                return [$frame->store, $frame->mode, $key];
            }

            // No active scope → fall through to the global mode.
            // Without an injected manager (e.g. direct construction in tests) we preserve
            // the old passthrough behaviour so existing unit tests are not disrupted.
            if ($this->manager === null) {
                return [null, null, null];
            }

            $globalMode = $this->manager->resolveMode();

            if ($globalMode === 'passthrough') {
                return [null, null, null];
            }

            $defaultStore = config('cassette.default', 'file');
            $store = $this->manager->store($defaultStore);
            $strategy = $this->manager->namingStrategy($defaultStore);
            $key = $strategy->key(new CassetteId('default', $hash, $type));

            return [$store, $globalMode, $key];
        }

        // Direct mode: use constructor values (existing behaviour, backward-compat).
        return [$this->store, $this->mode, $hash];
    }

    // ── Event dispatch ─────────────────────────────────────────────────────

    private function dispatchResolved(
        string $type,
        string $provider,
        string $model,
        Usage|EmbeddingsUsage $usage,
        bool $replayed,
        string $mode,
        string $key,
        ?string $recordedAt,
    ): void {
        [$group, $storeName, $onResolved] = $this->currentFrameInfo();

        event(new CassetteResolved(
            type: $type,
            provider: $provider,
            model: $model,
            usage: $usage,
            replayed: $replayed,
            mode: $mode,
            group: $group,
            storeName: $storeName,
            key: $key,
            recordedAt: $recordedAt,
            onResolved: $onResolved,
        ));
    }

    /** @return array{string, string, \Closure|null} */
    private function currentFrameInfo(): array
    {
        if ($this->mode === 'scope') {
            $frame = CassetteContext::current();
            if ($frame !== null) {
                return [$frame->group, $frame->storeName, $frame->onResolved];
            }

            if ($this->manager !== null) {
                return ['default', config('cassette.default', 'file'), null];
            }
        }

        return ['', '', null];
    }

    // ── Serialization ──────────────────────────────────────────────────────

    private function serializeEmbeddings(EmbeddingsRequest $request, EmbeddingsResponse $response, string $recordedAt): array
    {
        return [
            'recorded_at' => $recordedAt,
            'request' => [
                'type' => 'embeddings',
                'provider' => $request->provider(),
                'model' => $request->model(),
                'inputs' => $request->inputs(),
            ],
            'response' => [
                'embeddings' => array_map(function (Embedding $e) {
                    $packed = pack('g*', ...$e->embedding);

                    return ['packed' => base64_encode($packed), 'count' => count($e->embedding)];
                }, $response->embeddings),
                'usage' => $response->usage->toArray(),
                'meta' => $response->meta->toArray(),
            ],
        ];
    }

    private function hydrateEmbeddings(array $data): EmbeddingsResponse
    {
        $r = $data['response'];
        $embeddings = array_map(function (array $e) {
            $floats = array_values(unpack('g*', base64_decode($e['packed'])));

            return new Embedding($floats);
        }, $r['embeddings']);

        return new EmbeddingsResponse(
            embeddings: $embeddings,
            usage: new EmbeddingsUsage($r['usage']['tokens'] ?? null),
            meta: new Meta(id: $r['meta']['id'] ?? '', model: $r['meta']['model'] ?? ''),
        );
    }

    private function serializeText(TextRequest $request, TextResponse $response, string $recordedAt): array
    {
        return [
            'recorded_at' => $recordedAt,
            'request' => [
                'type' => 'text',
                'provider' => $request->provider(),
                'model' => $request->model(),
                'system_prompts' => array_map(fn ($sp) => $sp->content, $request->systemPrompts()),
                'messages' => array_map(
                    fn (Message $m) => method_exists($m, 'toArray') ? $m->toArray() : (array) $m,
                    $request->messages()
                ),
                'max_tokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
            ],
            'response' => [
                'text' => $response->text,
                'finish_reason' => $response->finishReason->value,
                'usage' => $response->usage->toArray(),
                'meta' => $response->meta->toArray(),
            ],
        ];
    }

    private function hydrateText(array $data): TextResponse
    {
        $r = $data['response'];

        return new TextResponse(
            steps: new Collection,
            text: $r['text'],
            finishReason: FinishReason::from($r['finish_reason']),
            toolCalls: [],
            toolResults: [],
            usage: new Usage(
                promptTokens: $r['usage']['prompt_tokens'] ?? 0,
                completionTokens: $r['usage']['completion_tokens'] ?? 0,
            ),
            meta: new Meta(id: $r['meta']['id'] ?? '', model: $r['meta']['model'] ?? ''),
            messages: new Collection,
        );
    }

    private function serializeStructured(StructuredRequest $request, StructuredResponse $response, string $recordedAt): array
    {
        return [
            'recorded_at' => $recordedAt,
            'request' => [
                'type' => 'structured',
                'provider' => $request->provider(),
                'model' => $request->model(),
                'system_prompts' => array_map(fn ($sp) => $sp->content, $request->systemPrompts()),
                'messages' => array_map(
                    fn (Message $m) => method_exists($m, 'toArray') ? $m->toArray() : (array) $m,
                    $request->messages()
                ),
                'schema' => $request->schema()->name(),
                'mode' => $request->mode()->name,
                'max_tokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
            ],
            'response' => [
                'structured' => $response->structured,
                'text' => $response->text,
                'finish_reason' => $response->finishReason->value,
                'usage' => $response->usage->toArray(),
                'meta' => $response->meta->toArray(),
            ],
        ];
    }

    private function hydrateStructured(array $data): StructuredResponse
    {
        $r = $data['response'];

        return new StructuredResponse(
            steps: new Collection,
            text: $r['text'],
            structured: $r['structured'],
            finishReason: FinishReason::from($r['finish_reason']),
            usage: new Usage(
                promptTokens: $r['usage']['prompt_tokens'] ?? 0,
                completionTokens: $r['usage']['completion_tokens'] ?? 0,
            ),
            meta: new Meta(id: $r['meta']['id'] ?? '', model: $r['meta']['model'] ?? ''),
        );
    }
}
