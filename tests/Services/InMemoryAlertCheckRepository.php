<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Repositories\AlertCheckRepositoryInterface;

/**
 * Pedidos de conferência em memória, com uma linha por fatura como a tabela real.
 */
final class InMemoryAlertCheckRepository implements AlertCheckRepositoryInterface
{
    /** @var array<int, string> invoiceid => solicitado_em */
    public array $rows = [];

    public bool $tableMissing = false;

    /** Simula outro processo alterando o pedido entre a leitura e o claim. */
    public ?string $touchOnClaim = null;

    public function request(int $invoiceId, string $requestedAt): void
    {
        if ($this->tableMissing) {
            throw new \RuntimeException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'mod_opennfse_alerta_checks' doesn't exist");
        }
        $this->rows[$invoiceId] = $requestedAt;
    }

    public function listDue(string $requestedBefore, int $limit): array
    {
        $due = array_filter($this->rows, static fn (string $at): bool => $at <= $requestedBefore);
        asort($due);
        $out = [];
        foreach (array_slice($due, 0, $limit, true) as $invoiceId => $at) {
            $out[] = ['invoiceid' => $invoiceId, 'solicitado_em' => $at];
        }

        return $out;
    }

    public function claim(int $invoiceId, string $requestedAt): bool
    {
        if ($this->touchOnClaim !== null) {
            $this->rows[$invoiceId] = $this->touchOnClaim;
        }
        if (($this->rows[$invoiceId] ?? null) !== $requestedAt) {
            return false;
        }
        unset($this->rows[$invoiceId]);

        return true;
    }
}
