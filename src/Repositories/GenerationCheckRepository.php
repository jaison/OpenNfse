<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class GenerationCheckRepository implements GenerationCheckRepositoryInterface
{
    private const TABLE = 'mod_opennfse_geracao_checks';

    private const RESCHEDULE_FIELDS = ['verificado_em', 'proxima_em', 'tentativas', 'erro_logado_em'];

    public function register(int $invoiceId, string $at, string $dueAt): void
    {
        Capsule::statement(
            'INSERT INTO ' . self::TABLE . ' (invoiceid, registrado_em, verificado_em, proxima_em, tentativas)'
            . ' VALUES (?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE invoiceid = invoiceid',
            [$invoiceId, $at, $at, $dueAt]
        );
    }

    public function exists(int $invoiceId): bool
    {
        return Capsule::table(self::TABLE)->where('invoiceid', $invoiceId)->first() !== null;
    }

    public function listDue(string $now, int $limit): array
    {
        $out = [];
        $rows = Capsule::table(self::TABLE)
            ->where('proxima_em', '<=', $now)
            ->orderBy('proxima_em')
            ->orderBy('invoiceid')
            ->limit(max(1, $limit))
            ->get();
        foreach ($rows as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    public function reschedule(int $invoiceId, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::RESCHEDULE_FIELDS));
        if ($fields === []) {
            return;
        }
        Capsule::table(self::TABLE)->where('invoiceid', $invoiceId)->update($fields);
    }

    public function delete(int $invoiceId): void
    {
        Capsule::table(self::TABLE)->where('invoiceid', $invoiceId)->delete();
    }
}
