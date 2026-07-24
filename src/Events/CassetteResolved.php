<?php

namespace Rushing\PrismCassette\Events;

use Closure;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\Contracts\RefinesEventMetering;

/**
 * Dispatched synchronously after every cassette interaction (record or replay).
 *
 * This event deliberately does NOT implement ShouldQueue — it can carry a Closure
 * (onResolved) that cannot be serialized, and it must fire before the response
 * is returned to the caller so that metering is synchronous.
 *
 * The package emits; the app listens. No metering or billing logic lives here.
 */
class CassetteResolved
{
    public function __construct(
        public string $type,
        public string $provider,
        public string $model,
        public Usage|EmbeddingsUsage $usage,
        public bool $replayed,
        public string $mode,
        public string $group,
        public string $storeName,
        public string $key,
        public ?string $recordedAt,
        public ?Closure $onResolved = null,
        /**
         * The provider's raw usage/billing array, verbatim, when the capability's serializer
         * implements {@see RefinesEventMetering} (e.g. rerank's
         * vendor `total_tokens` / `search_units`); null for capabilities whose typed {@see $usage}
         * already carries the full signal (text/embeddings/audio).
         *
         * @var array<string, mixed>|null
         */
        public ?array $rawUsage = null,
    ) {}
}
