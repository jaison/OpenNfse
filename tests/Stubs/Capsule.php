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

    public static function statement(string $sql): bool
    {
        self::$statements[] = $sql;
        return true;
    }

    /** @var array<string, array<int, array>> linhas recebidas por insert(), por tabela */
    public static array $inserts = [];

    /** @var array<int, string> tabelas que receberam delete() */
    public static array $deletes = [];

    public static function reset(): void
    {
        self::$inserts = [];
        self::$deletes = [];
        self::$rows = [];
        self::$statements = [];
    }
}

final class CapsuleQueryStub
{
    public function __construct(private string $table)
    {
    }

    public function where(...$args): self
    {
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

    public function first(): ?object
    {
        $rows = Capsule::$rows[$this->table] ?? [];
        return $rows[0] ?? null;
    }

    public function get(): array
    {
        return Capsule::$rows[$this->table] ?? [];
    }

    public function update(array $data): int
    {
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
