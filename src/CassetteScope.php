<?php

namespace Rushing\PrismCassette;

use Closure;

class CassetteScope
{
    private ?string $modeOverride = null;

    private ?Closure $onResolvedClosure = null;

    public function __construct(
        private CassetteManager $manager,
        private string $group,
        private ?string $storeName = null,
    ) {}

    public function record(): static
    {
        $clone = clone $this;
        $clone->modeOverride = 'record';

        return $clone;
    }

    public function replay(): static
    {
        $clone = clone $this;
        $clone->modeOverride = 'replay';

        return $clone;
    }

    public function passthrough(): static
    {
        $clone = clone $this;
        $clone->modeOverride = 'passthrough';

        return $clone;
    }

    public function onResolved(Closure $fn): static
    {
        $clone = clone $this;
        $clone->onResolvedClosure = $fn;

        return $clone;
    }

    public function play(Closure $fn): mixed
    {
        $storeName = $this->storeName ?? config('cassette.default', 'file');
        $store = $this->manager->store($storeName);
        $mode = $this->modeOverride ?? $this->manager->resolveMode($storeName);

        if ($mode === 'passthrough') {
            return $fn();
        }

        $frame = new CassetteContextFrame(
            group: $this->group,
            store: $store,
            mode: $mode,
            namingStrategy: $this->manager->namingStrategy($storeName),
            storeName: $storeName,
            onResolved: $this->onResolvedClosure,
        );

        CassetteContext::push($frame);
        try {
            return $fn();
        } finally {
            CassetteContext::pop();
        }
    }
}
