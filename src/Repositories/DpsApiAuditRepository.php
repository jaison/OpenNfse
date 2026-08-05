<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class DpsApiAuditRepository
{
    private const RUNS_TABLE = 'mod_opennfse_dps_audit_runs';
    private const RESULTS_TABLE = 'mod_opennfse_dps_audit_results';

    public function createRun(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $payload = [
            'audit_month' => (string) ($data['audit_month'] ?? ''),
            'status' => (string) ($data['status'] ?? 'pending'),
            'total_items' => (int) ($data['total_items'] ?? 0),
            'description' => (string) ($data['description'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return (int) Capsule::table(self::RUNS_TABLE)->insertGetId($payload);
    }

    public function findRun(int $runId): ?array
    {
        $row = Capsule::table(self::RUNS_TABLE)->where('id', $runId)->first();
        return $row ? (array) $row : null;
    }

    public function findLatestRunByMonth(string $month): ?array
    {
        $row = Capsule::table(self::RUNS_TABLE)
            ->where('audit_month', $month)
            ->orderBy('id', 'desc')
            ->first();
        return $row ? (array) $row : null;
    }

    public function updateRun(int $runId, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table(self::RUNS_TABLE)->where('id', $runId)->update($data);
    }

    public function upsertResult(array $data): void
    {
        $runId = (int) ($data['run_id'] ?? 0);
        $resultType = (string) ($data['result_type'] ?? 'DPS');
        if ($runId <= 0 || $resultType === '') {
            return;
        }

        $where = [
            'run_id' => $runId,
            'result_type' => $resultType,
        ];
        if ($resultType === 'DPS') {
            $where['history_id'] = isset($data['history_id']) ? (int) $data['history_id'] : null;
        } else {
            $where['numero_dps'] = isset($data['numero_dps']) ? (int) $data['numero_dps'] : null;
        }

        $now = date('Y-m-d H:i:s');
        $existing = Capsule::table(self::RESULTS_TABLE)->where($where)->first();
        if ($existing === null && $resultType === 'DPS') {
            $numeroDps = isset($data['numero_dps']) ? (int) $data['numero_dps'] : null;
            $idDps = trim((string) ($data['id_dps'] ?? ''));

            // A tabela mantém unicidade de DPS também por sequência dentro da run.
            // Se o histórico local trouxer linhas duplicadas para a mesma DPS,
            // reutilizamos o registro já persistido em vez de falhar o lote.
            if ($numeroDps !== null) {
                $existing = Capsule::table(self::RESULTS_TABLE)
                    ->where('run_id', $runId)
                    ->where('result_type', 'DPS')
                    ->where('numero_dps', $numeroDps)
                    ->first();
            }

            if ($existing === null && $idDps !== '') {
                $existing = Capsule::table(self::RESULTS_TABLE)
                    ->where('run_id', $runId)
                    ->where('result_type', 'DPS')
                    ->where('id_dps', $idDps)
                    ->first();
            }
        }
        $payload = [
            'run_id' => $runId,
            'result_type' => $resultType,
            'history_id' => isset($data['history_id']) ? (int) $data['history_id'] : null,
            'invoiceid' => isset($data['invoiceid']) ? (int) $data['invoiceid'] : null,
            'userid' => isset($data['userid']) ? (int) $data['userid'] : null,
            'event_date' => $data['event_date'] ?? null,
            'id_dps' => $data['id_dps'] ?? null,
            'numero_dps' => isset($data['numero_dps']) ? (int) $data['numero_dps'] : null,
            'numero_nf' => $data['numero_nf'] ?? null,
            'local_chave_acesso' => $data['local_chave_acesso'] ?? null,
            'api_chave_acesso' => $data['api_chave_acesso'] ?? null,
            'api_found' => isset($data['api_found']) ? (int) ((bool) $data['api_found']) : null,
            'audit_status' => (string) ($data['audit_status'] ?? ''),
            'audit_message' => $data['audit_message'] ?? null,
            'api_error' => $data['api_error'] ?? null,
            'evidence_classification' => $data['evidence_classification'] ?? null,
            'evidence_count' => isset($data['evidence_count']) ? (int) $data['evidence_count'] : 0,
        ];

        if ($existing === null) {
            $payload['created_at'] = $now;
            $payload['updated_at'] = $now;
            Capsule::table(self::RESULTS_TABLE)->insert($payload);
            return;
        }

        $payload['updated_at'] = $now;
        Capsule::table(self::RESULTS_TABLE)->where('id', (int) $existing->id)->update($payload);
    }

    public function clearGapResults(int $runId): void
    {
        Capsule::table(self::RESULTS_TABLE)
            ->where('run_id', $runId)
            ->where('result_type', 'GAP')
            ->delete();
    }

    public function listResults(int $runId, string $resultType, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        $query = Capsule::table(self::RESULTS_TABLE . ' as r')
            ->leftJoin('tblclients as c', 'c.id', '=', 'r.userid')
            ->select([
                'r.*',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->where('r.run_id', $runId)
            ->where('r.result_type', $resultType);

        if ($resultType === 'DPS') {
            $query->orderBy('r.history_id', 'desc');
        } else {
            $query->orderBy('r.numero_dps', 'asc');
        }

        if ($offset > 0) {
            $query->offset($offset);
        }

        $rows = [];
        foreach ($query->limit($limit)->get() as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    public function countResults(int $runId, string $resultType): int
    {
        return (int) Capsule::table(self::RESULTS_TABLE)
            ->where('run_id', $runId)
            ->where('result_type', $resultType)
            ->count('id');
    }
}
