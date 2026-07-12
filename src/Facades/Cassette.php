<?php

namespace Rushing\PrismCassette\Facades;

use Illuminate\Support\Facades\Facade;
use Rushing\PrismCassette\CassetteManager;

/**
 * @method static \Rushing\PrismCassette\Contracts\CassetteStore store(?string $name = null)
 * @method static \Rushing\PrismCassette\CassetteScope group(string $group, ?string $store = null)
 * @method static string resolveMode(?string $storeName = null)
 */
class Cassette extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CassetteManager::class;
    }
}
