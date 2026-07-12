<?php

namespace Rushing\PrismCassette;

class CassetteContext
{
    /** @var CassetteContextFrame[] */
    private static array $stack = [];

    public static function push(CassetteContextFrame $frame): void
    {
        self::$stack[] = $frame;
    }

    public static function pop(): void
    {
        array_pop(self::$stack);
    }

    public static function current(): ?CassetteContextFrame
    {
        return empty(self::$stack) ? null : end(self::$stack);
    }

    public static function reset(): void
    {
        self::$stack = [];
    }
}
