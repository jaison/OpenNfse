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
        // SHOW TABLES não aceita parâmetro no MariaDB; o information_schema aceita.
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%';
        $stmt = $this->pdo->prepare(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name LIKE ?"
        );
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

    /**
     * Lê sem buffer no cliente: a tabela de logs pode ser grande demais para a memória do PHP.
     * Enquanto o lote é lido, nenhuma outra consulta roda nesta conexão.
     */
    public function rowBatches(string $table, int $batchSize): iterable
    {
        $buffered = null;
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY') && $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $buffered = $this->pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        try {
            $stmt = $this->pdo->query('SELECT * FROM ' . self::ident($table));
            $batch = [];
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $batch[] = $row;
                if (count($batch) >= $batchSize) {
                    yield $batch;
                    $batch = [];
                }
            }
            $stmt->closeCursor();
            if ($batch !== []) {
                yield $batch;
            }
        } finally {
            if ($buffered !== null) {
                $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            }
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
