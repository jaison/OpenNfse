<?php

declare(strict_types=1);

namespace OpenNfse\Services;

/**
 * Fatura encerrada (Cancelled/Refunded) sem NFS-e emitida: o erro da fila deixa de ser pendência.
 * NFS-e emitida aqui é nota EMITIDA ou PROCESSANDO com chave de acesso.
 */
final class ClosedInvoiceQueuePolicy
{
    /** Cabe em mod_opennfse_logs.tipo (VARCHAR(30)); o nome com FATURA estoura em 31. */
    public const LOG_TYPE = 'FILA_RESOLVIDA_FAT_ENCERRADA';

    public const MANUAL_LOG_TYPE = 'FILA_RESOLVIDA_MANUAL';

    /** @var list<string> */
    public const CLOSED_STATUSES = ['Cancelled', 'Refunded'];

    /** @var list<string> */
    public const ISSUED_NOTA_STATUSES = ['EMITIDA', 'PROCESSANDO'];

    public static function shouldResolveQueueErrors(string $invoiceStatus, ?array $nota): bool
    {
        return self::isClosedInvoiceStatus($invoiceStatus) && !self::hasIssuedNfse($nota);
    }

    public static function isClosedInvoiceStatus(string $status): bool
    {
        $normalized = strtolower(trim($status));
        foreach (self::CLOSED_STATUSES as $closed) {
            if (strtolower($closed) === $normalized) {
                return true;
            }
        }

        return false;
    }

    public static function hasIssuedNfse(?array $nota): bool
    {
        if ($nota === null) {
            return false;
        }

        $status = strtoupper(trim((string) ($nota['status'] ?? '')));
        $chave = trim((string) ($nota['chave_acesso'] ?? ''));

        return $chave !== '' && in_array($status, self::ISSUED_NOTA_STATUSES, true);
    }

    /**
     * Fatura encerrada e sem NFS-e com chave. $invoiceIdColumn é um identificador fixo (ex.: n.invoiceid).
     */
    public static function closedWithoutIssuedNfseSql(string $invoiceIdColumn): string
    {
        self::assertColumn($invoiceIdColumn);
        $invoiceStatuses = self::sqlList(self::CLOSED_STATUSES);
        $notaStatuses = self::sqlList(self::ISSUED_NOTA_STATUSES);

        return $invoiceIdColumn . ' IN (SELECT closed_inv.id FROM tblinvoices closed_inv'
            . ' WHERE closed_inv.status IN (' . $invoiceStatuses . '))'
            . ' AND NOT EXISTS (SELECT 1 FROM mod_opennfse_notas issued_nf'
            . ' WHERE issued_nf.invoiceid = ' . $invoiceIdColumn
            . ' AND issued_nf.status IN (' . $notaStatuses . ')'
            . " AND issued_nf.chave_acesso IS NOT NULL AND issued_nf.chave_acesso <> '')";
    }

    /** Pendência de falha: fora o caso da fatura encerrada sem NFS-e. */
    public static function pendingFailureSql(string $invoiceIdColumn): string
    {
        return 'NOT (' . self::closedWithoutIssuedNfseSql($invoiceIdColumn) . ')';
    }

    /**
     * Feed da fila: RESOLVIDO continua visível; ERROR de fatura encerrada sem NFS-e sai.
     */
    public static function feedQueueSql(string $statusColumn, string $invoiceIdColumn): string
    {
        self::assertColumn($statusColumn);

        return '(' . $statusColumn . " <> 'ERROR' OR " . self::pendingFailureSql($invoiceIdColumn) . ')';
    }

    private static function assertColumn(string $column): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*\.[A-Za-z][A-Za-z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException('Coluna SQL inválida.');
        }
    }

    /**
     * @param list<string> $values
     */
    private static function sqlList(array $values): string
    {
        $quoted = [];
        foreach ($values as $value) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
                throw new \InvalidArgumentException('Valor SQL inválido.');
            }
            $quoted[] = "'" . $value . "'";
        }

        return implode(', ', $quoted);
    }
}
