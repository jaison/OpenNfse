<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Repositories\GenerationCheckRepositoryInterface;

/**
 * Conferências de criação em memória, com uma linha por fatura como a tabela real.
 */
final class InMemoryGenerationCheckRepository implements GenerationCheckRepositoryInterface
{
    /** @var array<int, array{invoiceid: int, registrado_em: string, verificado_em: string, proxima_em: string, tentativas: int, erro_logado_em: ?string}> */
    public array $rows = [];

    public bool $tableMissing = false;

    public function register(int $invoiceId, string $at, string $dueAt): void
    {
        if ($this->tableMissing) {
            throw new \RuntimeException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'mod_opennfse_geracao_checks' doesn't exist");
        }
        $this->rows[$invoiceId] ??= [
            'invoiceid' => $invoiceId,
            'registrado_em' => $at,
            'verificado_em' => $at,
            'proxima_em' => $dueAt,
            'tentativas' => 0,
            'erro_logado_em' => null,
        ];
    }

    public function exists(int $invoiceId): bool
    {
        return isset($this->rows[$invoiceId]);
    }

    public function listDue(string $now, int $limit): array
    {
        $due = array_values(array_filter($this->rows, static fn (array $r): bool => $r['proxima_em'] <= $now));
        usort($due, static fn (array $a, array $b): int => [$a['proxima_em'], $a['invoiceid']] <=> [$b['proxima_em'], $b['invoiceid']]);

        return array_slice($due, 0, $limit);
    }

    public function reschedule(int $invoiceId, array $fields): void
    {
        if (isset($this->rows[$invoiceId])) {
            $this->rows[$invoiceId] = array_merge($this->rows[$invoiceId], $fields);
        }
    }

    public function delete(int $invoiceId): void
    {
        unset($this->rows[$invoiceId]);
    }
}
