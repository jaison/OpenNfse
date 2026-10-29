<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class GenerationCheckRepository implements GenerationCheckRepositoryInterface
{
    private const TABLE = 'mod_opennfse_geracao_checks';

    public function register(int $invoiceId, string $at): void
    {
        Capsule::statement(
            'INSERT INTO ' . self::TABLE . ' (invoiceid, registrado_em, verificado_em) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE invoiceid = invoiceid',
            [$invoiceId, $at, $at]
        );
    }

    public function listPending(int $limit): array
    {
        $out = [];
        $rows = Capsule::table(self::TABLE)
            ->orderBy('verificado_em')
            ->orderBy('invoiceid')
            ->limit(max(1, $limit))
            ->get();
        foreach ($rows as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    public function touch(int $invoiceId, string $at): void
    {
        Capsule::table(self::TABLE)->where('invoiceid', $invoiceId)->update(['verificado_em' => $at]);
    }

    public function delete(int $invoiceId): void
    {
        Capsule::table(self::TABLE)->where('invoiceid', $invoiceId)->delete();
    }
}
