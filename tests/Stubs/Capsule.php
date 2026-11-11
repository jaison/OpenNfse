<?php

declare(strict_types=1);

namespace WHMCS\Database;

/**
 * Stub mínimo do Capsule do WHMCS para uso em testes.
 * Permite controlar as linhas retornadas por Capsule::table($name)->get().
 */
final class Capsule
{
    /** @var array<string, array<int, object>> */
    public static array $rows = [];

    public static function table(string $name): CapsuleQueryStub
    {
        return new CapsuleQueryStub($name);
    }

    /** @var array<int, string> statements recebidos por Capsule::statement() */
    public static array $statements = [];

    /** @var array<string, array<string, bool>> colunas que Capsule::schema()->hasColumn enxerga */
    public static array $columns = [];

    public static ?\Throwable $schemaError = null;

    public static ?\Throwable $getError = null;

    public static int $schemaCalls = 0;

    public static function statement(string $sql): bool
    {
        self::$statements[] = $sql;
        return true;
    }

    public static function schema(): CapsuleSchemaStub
    {
        self::$schemaCalls++;
        if (self::$schemaError !== null) {
            throw self::$schemaError;
        }

        return new CapsuleSchemaStub();
    }

    /** @var array<string, array<int, array>> linhas recebidas por insert(), por tabela */
    public static array $inserts = [];

    /** @var array<int, string> tabelas que receberam delete() */
    public static array $deletes = [];

    /** @var array<string, array<int, array>> dados recebidos por update(), por tabela */
    public static array $updates = [];

    public static function reset(): void
    {
        self::$inserts = [];
        self::$deletes = [];
        self::$updates = [];
        self::$rows = [];
        self::$statements = [];
        self::$columns = [];
        self::$schemaError = null;
        self::$getError = null;
        self::$schemaCalls = 0;
        if (class_exists(\OpenNfse\Repositories\LedgerCreditRepository::class)) {
            \OpenNfse\Repositories\LedgerCreditRepository::forgetColumnCache();
        }
    }
}

final class CapsuleQueryStub
{
    /** @var array<string, mixed> igualdades simples recebidas por where(), usadas só por exists() */
    private array $equals = [];

    public function __construct(private string $table)
    {
    }

    public function where(...$args): self
    {
        if (count($args) === 2 && is_string($args[0]) && is_scalar($args[1])) {
            $this->equals[$args[0]] = $args[1];
        } elseif (count($args) === 3 && is_string($args[0]) && $args[1] === '=' && is_scalar($args[2])) {
            $this->equals[$args[0]] = $args[2];
        }

        return $this;
    }

    public function orderBy(...$args): self
    {
        return $this;
    }

    public function whereIn(...$args): self
    {
        return $this;
    }

    public function select(...$args): self
    {
        return $this;
    }

    public function first(): ?object
    {
        $rows = Capsule::$rows[$this->table] ?? [];
        return $rows[0] ?? null;
    }

    public function get(): array
    {
        if (Capsule::$getError !== null) {
            throw Capsule::$getError;
        }

        return Capsule::$rows[$this->table] ?? [];
    }

    /** Considera só as igualdades simples do where(); a linha precisa ter a coluna com o mesmo valor. */
    public function exists(): bool
    {
        foreach (Capsule::$rows[$this->table] ?? [] as $row) {
            $row = (array) $row;
            $matches = true;
            foreach ($this->equals as $column => $value) {
                if (!array_key_exists($column, $row) || (string) $row[$column] !== (string) $value) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    public function update(array $data): int
    {
        Capsule::$updates[$this->table][] = $data;
        return 0;
    }

    public function delete(): int
    {
        Capsule::$deletes[] = $this->table;
        return 1;
    }

    public function insert(array $data): bool
    {
        Capsule::$inserts[$this->table][] = $data;
        return true;
    }
}

final class CapsuleSchemaStub
{
    public function hasColumn(string $table, string $column): bool
    {
        return (bool) (Capsule::$columns[$table][$column] ?? false);
    }
}
