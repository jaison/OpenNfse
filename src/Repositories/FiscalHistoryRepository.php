<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class FiscalHistoryRepository
{
    private const TABLE = 'mod_opennfse_notas_history';

    public function backfillCurrentStateIfNeeded(): void
    {
        $alreadyBackfilled = (int) Capsule::table(self::TABLE)
            ->where('origem', 'backfill')
            ->count('id');

        if ($alreadyBackfilled > 0) {
            return;
        }

        foreach (Capsule::table('mod_opennfse_notas')->get() as $row) {
            $this->backfillFromCurrentNota((array) $row);
        }
    }

    public function recordEmission(array $nota, string $origin = 'runtime'): void
    {
        $data = $this->mapNotaToHistory($nota, 'EMISSAO', $origin);
        $data['status_fiscal'] = 'EMITIDA';
        $data['fingerprint'] = sha1(implode('|', [
            'EMISSAO',
            (string) ($data['invoiceid'] ?? 0),
            (string) ($data['numero_nf'] ?? ''),
            (string) ($data['chave_acesso'] ?? ''),
            (string) ($data['emitida_em'] ?? ''),
        ]));

        $this->upsertHistoryRecord($data);
    }

    public function recordCancellation(array $nota, string $origin = 'runtime'): void
    {
        $data = $this->mapNotaToHistory($nota, 'CANCELAMENTO', $origin);
        $data['status_fiscal'] = 'CANCELADA';
        $data['fingerprint'] = sha1(implode('|', [
            'CANCELAMENTO',
            (string) ($data['invoiceid'] ?? 0),
            (string) ($data['numero_nf'] ?? ''),
            (string) ($data['cancelado_em'] ?? ''),
            (string) ($data['cancel_codigo_motivo'] ?? ''),
        ]));

        $this->upsertHistoryRecord($data);
    }

    public function recordSnapshot(array $nota, string $origin = 'runtime'): void
    {
        $data = $this->mapNotaToHistory($nota, 'SNAPSHOT', $origin);
        $data['status_fiscal'] = strtoupper(trim((string) ($nota['status'] ?? 'SEM_STATUS')));
        $data['fingerprint'] = sha1(implode('|', [
            'SNAPSHOT',
            (string) ($data['invoiceid'] ?? 0),
            (string) ($data['status_fiscal'] ?? ''),
            (string) ($data['numero_nf'] ?? ''),
            (string) ($data['emitida_em'] ?? ''),
            (string) ($data['cancelado_em'] ?? ''),
            (string) ($data['xml_path'] ?? ''),
            (string) ($data['cancel_xml_path'] ?? ''),
        ]));

        $this->upsertHistoryRecord($data);
    }

    public function listHistory(array $filters, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);

        $query = Capsule::table(self::TABLE . ' as h')
            ->join('tblclients as c', 'c.id', '=', 'h.userid')
            ->leftJoin('tblinvoices as i', 'i.id', '=', 'h.invoiceid')
            ->leftJoin('tblcurrencies as cur', 'cur.id', '=', 'c.currency')
            ->select([
                'h.id',
                'h.nota_id',
                'h.invoiceid',
                'h.userid',
                'h.tipo_registro',
                'h.origem',
                'h.status_fiscal',
                'h.numero_nf',
                'h.protocolo',
                'h.id_dps',
                'h.chave_acesso',
                'h.xml_path',
                'h.cancel_xml_path',
                'h.competencia',
                'h.emitida_em',
                'h.cancelado_em',
                'h.cancel_codigo_motivo',
                'h.cancel_motivo',
                'h.cancel_descricao',
                'h.erro_api',
                'h.cancel_erro',
                'h.created_at',
                'i.total as invoice_total',
                'c.companyname',
                'c.firstname',
                'c.lastname',
                'cur.prefix as currency_prefix',
                'cur.suffix as currency_suffix',
            ]);

        $this->applyHistoryFilters($query, $filters);

        if ($offset > 0) {
            $query->offset($offset);
        }

        $rows = [];
        foreach ($query
            ->orderByRaw('COALESCE(h.cancelado_em, h.emitida_em, h.created_at) DESC')
            ->orderBy('h.id', 'desc')
            ->limit($limit)
            ->get() as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    public function summaryHistory(array $filters): array
    {
        $row = Capsule::table(self::TABLE . ' as h')
            ->select([
                Capsule::raw('COUNT(*) as total_docs'),
                Capsule::raw("SUM(CASE WHEN h.tipo_registro = 'EMISSAO' THEN 1 ELSE 0 END) as total_emissoes"),
                Capsule::raw("SUM(CASE WHEN h.tipo_registro = 'CANCELAMENTO' THEN 1 ELSE 0 END) as total_cancelamentos"),
                Capsule::raw("SUM(CASE WHEN h.tipo_registro = 'SNAPSHOT' THEN 1 ELSE 0 END) as total_snapshots"),
            ]);

        $this->applyHistoryFilters($row, $filters);

        $data = $row->first();
        $arr = $data ? (array) $data : [];

        return [
            'total_docs' => (int) ($arr['total_docs'] ?? 0),
            'total_emissoes' => (int) ($arr['total_emissoes'] ?? 0),
            'total_cancelamentos' => (int) ($arr['total_cancelamentos'] ?? 0),
            'total_snapshots' => (int) ($arr['total_snapshots'] ?? 0),
        ];
    }

    public function listComparableHistoryByMonth(string $month): array
    {
        $query = Capsule::table(self::TABLE . ' as h')
            ->join('tblclients as c', 'c.id', '=', 'h.userid')
            ->leftJoin('tblinvoices as i', 'i.id', '=', 'h.invoiceid')
            ->select([
                'h.id',
                'h.invoiceid',
                'h.userid',
                'h.tipo_registro',
                'h.origem',
                'h.status_fiscal',
                'h.numero_nf',
                'h.chave_acesso',
                'h.xml_path',
                'h.cancel_xml_path',
                'h.emitida_em',
                'h.cancelado_em',
                'h.created_at',
                'i.total as invoice_total',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->whereIn('h.tipo_registro', ['EMISSAO', 'CANCELAMENTO']);

        $this->applyHistoryFilters($query, ['month' => $month]);

        $rows = [];
        foreach ($query
            ->orderByRaw('COALESCE(h.cancelado_em, h.emitida_em, h.created_at) DESC')
            ->orderBy('h.id', 'desc')
            ->get() as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    public function summaryComparableHistoryByMonth(string $month): array
    {
        $query = Capsule::table(self::TABLE . ' as h')
            ->select([
                Capsule::raw('COUNT(*) as total_docs'),
                Capsule::raw("SUM(CASE WHEN h.tipo_registro = 'EMISSAO' THEN 1 ELSE 0 END) as total_emissoes"),
                Capsule::raw("SUM(CASE WHEN h.tipo_registro = 'CANCELAMENTO' THEN 1 ELSE 0 END) as total_cancelamentos"),
            ])
            ->whereIn('h.tipo_registro', ['EMISSAO', 'CANCELAMENTO']);

        $this->applyHistoryFilters($query, ['month' => $month]);

        $row = $query->first();
        $arr = $row ? (array) $row : [];

        return [
            'total_docs' => (int) ($arr['total_docs'] ?? 0),
            'total_emissoes' => (int) ($arr['total_emissoes'] ?? 0),
            'total_cancelamentos' => (int) ($arr['total_cancelamentos'] ?? 0),
        ];
    }

    private function backfillFromCurrentNota(array $nota): void
    {
        $hasEmission = $this->hasEmissionData($nota);
        $hasCancellation = $this->hasCancellationData($nota);

        if ($hasEmission) {
            $this->recordEmission($nota, 'backfill');
        }

        if ($hasCancellation) {
            $this->recordCancellation($nota, 'backfill');
        }

        if (!$hasEmission && !$hasCancellation) {
            $this->recordSnapshot($nota, 'backfill');
        }
    }

    private function mapNotaToHistory(array $nota, string $tipoRegistro, string $origin): array
    {
        return [
            'nota_id' => (int) ($nota['id'] ?? 0) ?: null,
            'invoiceid' => (int) ($nota['invoiceid'] ?? 0),
            'userid' => (int) ($nota['userid'] ?? 0),
            'tipo_registro' => $tipoRegistro,
            'origem' => $origin,
            'status_fiscal' => strtoupper(trim((string) ($nota['status'] ?? 'SEM_STATUS'))),
            'numero_nf' => $this->normalizeNullableString($nota['numero_nf'] ?? null),
            'protocolo' => $this->normalizeNullableString($nota['protocolo'] ?? null),
            'id_dps' => $this->normalizeNullableString($nota['id_dps'] ?? null),
            'chave_acesso' => $this->normalizeNullableString($nota['chave_acesso'] ?? null),
            'xml_path' => $this->normalizeNullableString($nota['xml_path'] ?? null),
            'cancel_xml_path' => $this->normalizeNullableString($nota['cancel_xml_path'] ?? null),
            'competencia' => $this->normalizeNullableString($nota['competencia'] ?? null),
            'emitida_em' => $this->normalizeNullableDateTime($nota['emitida_em'] ?? null),
            'cancelado_em' => $this->normalizeNullableDateTime($nota['cancelado_em'] ?? null),
            'cancel_codigo_motivo' => $this->normalizeNullableString($nota['cancel_codigo_motivo'] ?? null),
            'cancel_motivo' => $this->normalizeNullableString($nota['cancel_motivo'] ?? null),
            'cancel_descricao' => $this->normalizeNullableString($nota['cancel_descricao'] ?? null),
            'erro_api' => $this->normalizeNullableString($nota['erro_api'] ?? null),
            'cancel_erro' => $this->normalizeNullableString($nota['cancel_erro'] ?? null),
        ];
    }

    private function upsertHistoryRecord(array $data): void
    {
        $fingerprint = (string) ($data['fingerprint'] ?? '');
        if ($fingerprint === '') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $existing = Capsule::table(self::TABLE)->where('fingerprint', $fingerprint)->first();

        if ($existing === null) {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            Capsule::table(self::TABLE)->insert($data);
            return;
        }

        unset($data['fingerprint']);
        $data['updated_at'] = $now;
        Capsule::table(self::TABLE)->where('id', (int) $existing->id)->update($data);
    }

    private function applyHistoryFilters($query, array $filters): void
    {
        $month = trim((string) ($filters['month'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            try {
                $start = new \DateTimeImmutable($month . '-01 00:00:00');
                $end = $start->modify('last day of this month')->setTime(23, 59, 59);
                $query->whereRaw('COALESCE(h.cancelado_em, h.emitida_em, h.created_at) >= ?', [$start->format('Y-m-d H:i:s')]);
                $query->whereRaw('COALESCE(h.cancelado_em, h.emitida_em, h.created_at) <= ?', [$end->format('Y-m-d H:i:s')]);
            } catch (\Throwable $e) {
            }
        }

        $tipoRegistro = strtoupper(trim((string) ($filters['tipo_registro'] ?? '')));
        if ($tipoRegistro !== '' && in_array($tipoRegistro, ['EMISSAO', 'CANCELAMENTO', 'SNAPSHOT'], true)) {
            $query->where('h.tipo_registro', $tipoRegistro);
        }

        $invoiceId = trim((string) ($filters['invoiceid'] ?? ''));
        if ($invoiceId !== '' && ctype_digit($invoiceId)) {
            $query->where('h.invoiceid', (int) $invoiceId);
        }
    }

    private function hasEmissionData(array $nota): bool
    {
        return $this->normalizeNullableString($nota['numero_nf'] ?? null) !== null
            || $this->normalizeNullableString($nota['chave_acesso'] ?? null) !== null
            || $this->normalizeNullableString($nota['xml_path'] ?? null) !== null
            || $this->normalizeNullableDateTime($nota['emitida_em'] ?? null) !== null;
    }

    private function hasCancellationData(array $nota): bool
    {
        return strtoupper(trim((string) ($nota['status'] ?? ''))) === 'CANCELADA'
            || $this->normalizeNullableDateTime($nota['cancelado_em'] ?? null) !== null
            || $this->normalizeNullableString($nota['cancel_codigo_motivo'] ?? null) !== null
            || $this->normalizeNullableString($nota['cancel_xml_path'] ?? null) !== null;
    }

    private function normalizeNullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function normalizeNullableDateTime($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return $value;
    }
}
