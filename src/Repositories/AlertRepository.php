<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class AlertRepository implements AlertRepositoryInterface
{
    private const TABLE = 'mod_opennfse_alertas';

    public function findOpenByKey(string $openKey): ?array
    {
        $row = Capsule::table(self::TABLE)
            ->where('chave_aberto', $openKey)
            ->whereNull('resolvido_em')
            ->first();

        return $row ? (array) $row : null;
    }

    public function insert(array $row): int
    {
        return (int) Capsule::table(self::TABLE)->insertGetId($row);
    }

    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = Capsule::table(self::TABLE)->where('id', $id)->first();

        return $row ? (array) $row : null;
    }

    public function markResolved(int $id, int $adminId, string $resolvedAt): bool
    {
        if ($id <= 0) {
            return false;
        }

        $updated = Capsule::table(self::TABLE)
            ->where('id', $id)
            ->whereNull('resolvido_em')
            ->update([
                'resolvido_em' => $resolvedAt,
                'resolvido_por' => $adminId > 0 ? $adminId : null,
                'chave_aberto' => null,
            ]);

        return (int) $updated === 1;
    }

    public function countOpen(): int
    {
        return (int) Capsule::table(self::TABLE)->whereNull('resolvido_em')->count();
    }

    /**
     * @param array{situacao?: string, invoiceid?: int, tipo?: string} $filters
     */
    public function listWithContext(array $filters, int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $situacao = (string) ($filters['situacao'] ?? 'abertos');
        $invoiceId = (int) ($filters['invoiceid'] ?? 0);
        $tipo = trim((string) ($filters['tipo'] ?? ''));

        $q = Capsule::table(self::TABLE . ' as a')
            ->leftJoin('tblinvoices as i', 'i.id', '=', 'a.invoiceid')
            ->leftJoin('tblclients as c', 'c.id', '=', 'i.userid')
            ->leftJoin('mod_opennfse_notas as n', 'n.invoiceid', '=', 'a.invoiceid')
            ->leftJoin('tbladmins as adm', 'adm.id', '=', 'a.resolvido_por')
            ->select([
                'a.id',
                'a.invoiceid',
                'a.nota_id',
                'a.chave_acesso',
                'a.tipo',
                'a.detalhes',
                'a.criado_em',
                'a.resolvido_em',
                'a.resolvido_por',
                'i.status as invoice_status',
                'i.userid',
                'c.companyname',
                'c.firstname',
                'c.lastname',
                'n.status as nota_status',
                'n.numero_nf as nota_numero_nf',
                'n.chave_acesso as nota_chave_acesso',
                'adm.username as resolvido_por_username',
            ]);

        if ($situacao === 'abertos') {
            $q->whereNull('a.resolvido_em');
        } elseif ($situacao === 'resolvidos') {
            $q->whereNotNull('a.resolvido_em');
        }
        if ($invoiceId > 0) {
            $q->where('a.invoiceid', $invoiceId);
        }
        if ($tipo !== '') {
            $q->where('a.tipo', $tipo);
        }

        $out = [];
        foreach ($q->orderBy('a.criado_em', 'desc')->orderBy('a.id', 'desc')->limit($limit)->get() as $r) {
            $out[] = (array) $r;
        }

        return $out;
    }
}
