<?php

namespace Rushing\PrismCassette\Support;

use Rushing\PrismCassette\Contracts\NamingStrategy;

class ContentAddressedStrategy implements NamingStrategy
{
    public function key(CassetteId $id): string
    {
        return $id->group.'/'.$id->type
            .'/'.substr($id->hash, 0, 2)
            .'/'.substr($id->hash, 2, 2)
            .'/'.$id->hash;
    }
}
