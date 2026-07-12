<?php

namespace Rushing\PrismCassette\Drivers;

use PDO;
use Rushing\PrismCassette\Contracts\CassetteStore;

class SqliteCassetteStore implements CassetteStore
{
    private PDO $pdo;

    public function __construct(string $database)
    {
        $dir = dirname($database);

        if ($dir !== '' && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:'.$database);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cassettes (key TEXT PRIMARY KEY, payload TEXT NOT NULL)'
        );
    }

    public function has(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM cassettes WHERE key = ?');
        $stmt->execute([$key]);

        return $stmt->fetch() !== false;
    }

    public function get(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM cassettes WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function put(string $key, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO cassettes (key, payload) VALUES (?, ?)'
        );
        $stmt->execute([$key, json_encode($data, JSON_THROW_ON_ERROR)]);
    }

    public function forget(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cassettes WHERE key = ?');
        $stmt->execute([$key]);
    }

    /** @return string[] */
    public function keys(): array
    {
        $stmt = $this->pdo->query('SELECT key FROM cassettes ORDER BY key');

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
}
