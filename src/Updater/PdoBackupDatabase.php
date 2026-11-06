<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

use WHMCS\Database\Capsule;

final class PdoBackupDatabase implements BackupDatabase
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Capsule::connection()->getPdo();
    }

    public function tablesWithPrefix(string $prefix): array
    {
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%';
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$like]);
        $tables = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        sort($tables);

        return $tables;
    }

    public function createStatement(string $table): string
    {
        $row = $this->pdo->query('SHOW CREATE TABLE ' . self::ident($table))->fetch(\PDO::FETCH_NUM);

        return (string) ($row[1] ?? '');
    }

    public function rowBatches(string $table, int $batchSize): iterable
    {
        $stmt = $this->pdo->query('SELECT * FROM ' . self::ident($table));
        $batch = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $batch[] = $row;
            if (count($batch) >= $batchSize) {
                yield $batch;
                $batch = [];
            }
        }
        if ($batch !== []) {
            yield $batch;
        }
    }

    public function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return (string) $this->pdo->quote((string) $value);
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public static function ident(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Nome de tabela inválido: ' . $name);
        }

        return '`' . $name . '`';
    }
}
