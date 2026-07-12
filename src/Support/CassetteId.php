<?php

namespace Rushing\PrismCassette\Support;

class CassetteId
{
    public function __construct(
        public string $group,
        public string $hash,
        public string $type,
    ) {}
}
