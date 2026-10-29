<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class AlertCheckRepository implements AlertCheckRepositoryInterface
{
    private const TABLE = 'mod_opennfse_alerta_checks';

    public function request(int $invoiceId, string $requestedAt): void
    {
        Capsule::statement(
            'INSERT INTO ' . self::TABLE . ' (invoiceid, solicitado_em) VALUES (?, ?)'
            . ' ON DUPLICATE KEY UPDATE solicitado_em = VALUES(solicitado_em)',
            [$invoiceId, $requestedAt]
        );
    }

    public function listDue(string $requestedBefore, int $limit): array
    {
        $out = [];
        $rows = Capsule::table(self::TABLE)
            ->where('solicitado_em', '<=', $requestedBefore)
            ->orderBy('solicitado_em')
            ->limit(max(1, $limit))
            ->get();
        foreach ($rows as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    public function claim(int $invoiceId, string $requestedAt): bool
    {
        return (int) Capsule::table(self::TABLE)
            ->where('invoiceid', $invoiceId)
            ->where('solicitado_em', $requestedAt)
            ->delete() === 1;
    }
}
