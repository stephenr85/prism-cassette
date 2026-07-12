<?php

namespace Rushing\PrismCassette\Exceptions;

use RuntimeException;

class CassetteDisarmedException extends RuntimeException
{
    public static function forScope(string $group, string $mode): self
    {
        return new self(
            "Cassette scope [{$group}] resolved to [{$mode}] mode, but no Prism providers were armed at boot — ".
            "the calls inside the scope would run live against the provider and nothing would be recorded or replayed.\n".
            "Check the 'cassette.providers' config (each listed provider must resolve through Prism), ".
            'then run `php artisan cassette:status` to see what armed.'
        );
    }
}
