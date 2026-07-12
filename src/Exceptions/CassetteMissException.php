<?php

namespace Rushing\PrismCassette\Exceptions;

use RuntimeException;

class CassetteMissException extends RuntimeException
{
    public static function make(string $type, string $provider, string $model, string $key, string $inputPreview): self
    {
        return new self(
            "Cassette miss in replay mode.\n".
            "Type: {$type} | Provider: {$provider} | Model: {$model}\n".
            "Key: {$key}\n".
            "Input preview: {$inputPreview}"
        );
    }
}
