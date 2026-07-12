<?php

namespace Rushing\PrismCassette\Events;

use Closure;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Usage;

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
    ) {}
}
