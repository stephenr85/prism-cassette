<?php

namespace Rushing\PrismCassette\Exceptions;

use RuntimeException;

class CassetteDisarmedException extends RuntimeException
{
    public static function forScope(string $group, string $mode): self
    {
        return new self(
            "Cassette scope [{$group}] resolved to [{$mode}] mode, but nothing was armed at boot — no Prism ".
            'providers armed and no non-Prism capabilities declared tape-able — so the calls inside the scope '.
            "would run live and nothing would be recorded or replayed.\n".
            "For a Prism provider, check the 'cassette.providers' config (each listed provider must resolve ".
            'through Prism). For a non-Prism capability (e.g. rerank), call CassetteManager::armCapability() '.
            'from your service provider. Run `php artisan cassette:status` to see what armed.'
        );
    }
}
