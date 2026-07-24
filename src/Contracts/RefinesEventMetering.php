<?php

namespace Rushing\PrismCassette\Contracts;

use Rushing\PrismCassette\Events\CassetteResolved;

/**
 * Optional companion to {@see CassetteSerializer} for a capability whose full metering identity is not
 * knowable from the request alone — it lives on the RESPONSE. The generic tape engine keys and reports
 * provider from the request, but a capability like PrismPlus rerank resolves its model server-side
 * (a null `model` means "provider default", filled in only on the way back) and bills in a shape that
 * isn't token-based (Voyage `total_tokens`, Cohere `search_units`).
 *
 * A serializer that implements this refines the {@see CassetteResolved}
 * event from the (live or hydrated) response: the RESOLVED model for the event's `model` field, and
 * the raw vendor usage array verbatim on `rawUsage`. The cassette KEY and the replay-miss exception
 * still use {@see CassetteSerializer::model()} (request-derived) — only the metering event is refined.
 *
 * Purely additive: serializers that don't implement it (TTS/STT/text/embeddings) meter exactly as
 * before — request-derived model, null rawUsage.
 */
interface RefinesEventMetering
{
    /** The resolved model id from a (live or hydrated) response, for the metering event's `model`. */
    public function resolvedModel(object $response): string;

    /**
     * The provider's usage/billing payload, verbatim, from a (live or hydrated) response.
     *
     * @return array<string, mixed>
     */
    public function rawUsage(object $response): array;
}
