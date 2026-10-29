<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Repositories\GenerationCheckRepositoryInterface;

/**
 * Conferências de criação em memória, com uma linha por fatura como a tabela real.
 */
final class InMemoryGenerationCheckRepository implements GenerationCheckRepositoryInterface
{
    /** @var array<int, array{invoiceid: int, registrado_em: string, verificado_em: string}> */
    public array $rows = [];

    public bool $tableMissing = false;

    public function register(int $invoiceId, string $at): void
    {
        if ($this->tableMissing) {
            throw new \RuntimeException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'mod_opennfse_geracao_checks' doesn't exist");
        }
        $this->rows[$invoiceId] ??= ['invoiceid' => $invoiceId, 'registrado_em' => $at, 'verificado_em' => $at];
    }

    public function listPending(int $limit): array
    {
        $rows = array_values($this->rows);
        usort($rows, static fn (array $a, array $b): int => [$a['verificado_em'], $a['invoiceid']] <=> [$b['verificado_em'], $b['invoiceid']]);

        return array_slice($rows, 0, $limit);
    }

    public function touch(int $invoiceId, string $at): void
    {
        if (isset($this->rows[$invoiceId])) {
            $this->rows[$invoiceId]['verificado_em'] = $at;
        }
    }

    public function delete(int $invoiceId): void
    {
        unset($this->rows[$invoiceId]);
    }
}
