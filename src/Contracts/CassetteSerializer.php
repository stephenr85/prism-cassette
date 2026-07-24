<?php

namespace Rushing\PrismCassette\Contracts;

use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteManager;

/**
 * The extension seam for taping a capability cassette does not natively know how to serialize.
 *
 * CassetteProvider's built-in capabilities (text/structured/embeddings) tape inline. Every OTHER
 * capability — Prism-native audio (TTS/STT), or a capability owned by a DOWNSTREAM package that
 * cassette has no dependency on (e.g. PrismPlus rerank) — is taped through a serializer registered
 * on {@see CassetteManager::registerSerializer()}. This keeps cassette
 * closed-for-modification, open-for-extension: a new modality contributes its serializer instead of
 * forcing an edit here. Registration is soft — a downstream package guards with
 * `class_exists(CassetteSerializer::class)` so it never takes a hard dependency on cassette.
 *
 * A serializer owns everything type-specific about ONE capability: the lookup key, the (provider,
 * model) identity for the CassetteResolved metering event, the record payload, and the hydration
 * back into the provider's own response value object. `$request`/`$response` are typed `object`
 * because each capability carries its own Prism (or downstream) request/response classes; the
 * implementation narrows them.
 */
interface CassetteSerializer
{
    /** Content-addressed lookup key for this request (same input → same key). */
    public function key(object $request): string;

    /** Provider key of the request (for the CassetteResolved event). */
    public function provider(object $request): string;

    /** Model id of the request (for the CassetteResolved event + miss exception). */
    public function model(object $request): string;

    /** Short human preview of the request, shown in a replay-miss exception. */
    public function preview(object $request): string;

    /**
     * Serialize a live response into a storable cassette payload. MUST include a top-level
     * `recorded_at` key so {@see hydrate()} and the resolved event can echo provenance.
     *
     * @return array<string, mixed>
     */
    public function serialize(object $request, object $response, string $recordedAt): array;

    /**
     * Rebuild the provider's own response value object from a stored payload.
     *
     * @param  array<string, mixed>  $data
     */
    public function hydrate(array $data): object;

    /** Usage carried by a (live or hydrated) response, for the metering event. Zero when the modality reports none. */
    public function usage(object $response): Usage|EmbeddingsUsage;
}
