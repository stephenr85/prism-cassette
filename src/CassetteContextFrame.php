<?php

namespace Rushing\PrismCassette;

use Closure;
use Rushing\PrismCassette\Contracts\CassetteStore;
use Rushing\PrismCassette\Contracts\NamingStrategy;

class CassetteContextFrame
{
    public function __construct(
        public string $group,
        public CassetteStore $store,
        public string $mode,
        public NamingStrategy $namingStrategy,
        public string $storeName = '',
        public ?Closure $onResolved = null,
    ) {}
}
