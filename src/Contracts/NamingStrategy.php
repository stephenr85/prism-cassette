<?php

namespace Rushing\PrismCassette\Contracts;

use Rushing\PrismCassette\Support\CassetteId;

interface NamingStrategy
{
    public function key(CassetteId $id): string;
}
