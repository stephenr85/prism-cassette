<?php

namespace Rushing\PrismCassette\Contracts;

interface CassetteStore
{
    public function has(string $key): bool;

    public function get(string $key): ?array;

    /** @param array<string, mixed> $data */
    public function put(string $key, array $data): void;

    public function forget(string $key): void;

    /** @return string[] */
    public function keys(): array;
}
