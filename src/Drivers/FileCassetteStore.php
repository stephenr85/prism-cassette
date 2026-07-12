<?php

namespace Rushing\PrismCassette\Drivers;

use Rushing\PrismCassette\Contracts\CassetteStore;

class FileCassetteStore implements CassetteStore
{
    public function __construct(protected string $basePath) {}

    public function has(string $key): bool
    {
        return file_exists($this->path($key));
    }

    public function get(string $key): ?array
    {
        $path = $this->path($key);

        if (! file_exists($path)) {
            return null;
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function put(string $key, array $data): void
    {
        $path = $this->path($key);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /** @return string[] */
    public function keys(): array
    {
        $base = rtrim($this->basePath, '/');

        if (! is_dir($base)) {
            return [];
        }

        $keys = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $rel = substr($file->getPathname(), strlen($base) + 1);
            $rel = substr($rel, 0, -5); // strip .json

            // Detect sharded bare-hash paths: {2}/{2}/{64} where segments match hash prefix.
            if (preg_match('#^([0-9a-f]{2})/([0-9a-f]{2})/([0-9a-f]{64})$#', $rel, $m)
                && $m[1] === substr($m[3], 0, 2)
                && $m[2] === substr($m[3], 2, 2)
            ) {
                $keys[] = $m[3];
            } else {
                $keys[] = $rel;
            }
        }

        return $keys;
    }

    protected function path(string $key): string
    {
        if (str_contains($key, '/')) {
            // Naming-strategy key: treat as a relative path directly.
            return rtrim($this->basePath, '/').'/'.$key.'.json';
        }

        // Bare hash key: two-level sharding to avoid huge flat dirs.
        return rtrim($this->basePath, '/').'/'
            .substr($key, 0, 2).'/'
            .substr($key, 2, 2).'/'
            .$key.'.json';
    }
}
