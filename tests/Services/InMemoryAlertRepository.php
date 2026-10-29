<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Repositories\AlertRepositoryInterface;

/**
 * Repositório em memória que imita o índice único de chave_aberto.
 */
final class InMemoryAlertRepository implements AlertRepositoryInterface
{
    /** @var array<int, array> */
    public array $rows = [];

    public int $nextId = 1;

    public bool $tableMissing = false;

    /** Simula outro pedido gravando o mesmo alerta entre a leitura e a gravação. */
    public bool $hideOpenOnRead = false;

    public function findOpenByKey(string $openKey): ?array
    {
        $this->assertTable();
        if ($this->hideOpenOnRead) {
            return null;
        }
        foreach ($this->rows as $row) {
            if ($row['chave_aberto'] === $openKey && $row['resolvido_em'] === null) {
                return $row;
            }
        }

        return null;
    }

    public function insert(array $row): int
    {
        $this->assertTable();
        foreach ($this->rows as $existing) {
            if ($existing['chave_aberto'] !== null && $existing['chave_aberto'] === $row['chave_aberto']) {
                throw new \RuntimeException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '" . $row['chave_aberto'] . "' for key 'uq_nfse_alerta_aberto'");
            }
        }
        $id = $this->nextId++;
        $row['id'] = $id;
        $this->rows[$id] = $row;

        return $id;
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function markResolved(int $id, int $adminId, string $resolvedAt): bool
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]['resolvido_em'] !== null) {
            return false;
        }
        $this->rows[$id]['resolvido_em'] = $resolvedAt;
        $this->rows[$id]['resolvido_por'] = $adminId > 0 ? $adminId : null;
        $this->rows[$id]['chave_aberto'] = null;

        return true;
    }

    private function assertTable(): void
    {
        if ($this->tableMissing) {
            throw new \RuntimeException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'whmcs.mod_opennfse_alertas' doesn't exist");
        }
    }
}
