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

    public static function reset(): void
    {
        self::$rows = [];
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

    public function insert(array $data): bool
    {
        return true;
    }
}
