<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class ApiAuditRepository
{
    public function getSyncState(string $environment): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $row = Capsule::table('mod_opennfse_nsu_sync')
            ->where('environment', $environment)
            ->first();

        if ($row === null) {
            return [
                'environment' => $environment,
                'cnpj_consulta' => null,
                'ultimo_nsu' => 0,
                'maior_nsu' => null,
                'ultimo_sync_em' => null,
            ];
        }

        return (array) $row;
    }

    public function upsertSyncState(array $data): void
    {
        $environment = $this->normalizeEnvironment((string) ($data['environment'] ?? ''));
        $now = date('Y-m-d H:i:s');
        $payload = [
            'environment' => $environment,
            'cnpj_consulta' => $this->nullableDigits($data['cnpj_consulta'] ?? null),
            'ultimo_nsu' => max(0, (int) ($data['ultimo_nsu'] ?? 0)),
            'maior_nsu' => isset($data['maior_nsu']) && $data['maior_nsu'] !== null ? max(0, (int) $data['maior_nsu']) : null,
            'ultimo_sync_em' => $this->nullableString($data['ultimo_sync_em'] ?? null),
        ];

        $existing = Capsule::table('mod_opennfse_nsu_sync')
            ->where('environment', $environment)
            ->first();

        if ($existing === null) {
            $payload['created_at'] = $now;
            $payload['updated_at'] = $now;
            Capsule::table('mod_opennfse_nsu_sync')->insert($payload);
            return;
        }

        $payload['updated_at'] = $now;
        Capsule::table('mod_opennfse_nsu_sync')
            ->where('id', (int) $existing->id)
            ->update($payload);
    }

    public function upsertDistributedDocument(array $data): void
    {
        $environment = $this->normalizeEnvironment((string) ($data['environment'] ?? ''));
        $nsu = max(0, (int) ($data['nsu'] ?? 0));
        if ($nsu <= 0) {
            return;
        }

        $payload = [
            'environment' => $environment,
            'nsu' => $nsu,
            'chave_acesso' => $this->nullableString($data['chave_acesso'] ?? null),
            'tipo_documento' => $this->normalizeDocType((string) ($data['tipo_documento'] ?? 'DESCONHECIDO')),
            'tipo_evento' => $this->nullableString($data['tipo_evento'] ?? null),
            'numero_nf' => $this->nullableString($data['numero_nf'] ?? null),
            'competencia' => $this->nullableDate($data['competencia'] ?? null),
            'emitida_em' => $this->nullableDateTime($data['emitida_em'] ?? null),
            'evento_em' => $this->nullableDateTime($data['evento_em'] ?? null),
            'referencia_em' => $this->nullableDateTime($data['referencia_em'] ?? null),
            'xml_hash' => $this->nullableString($data['xml_hash'] ?? null),
        ];

        $now = date('Y-m-d H:i:s');
        $existing = Capsule::table('mod_opennfse_distribuicao_dfe')
            ->where('environment', $environment)
            ->where('nsu', $nsu)
            ->first();

        if ($existing === null) {
            $payload['created_at'] = $now;
            $payload['updated_at'] = $now;
            Capsule::table('mod_opennfse_distribuicao_dfe')->insert($payload);
            return;
        }

        $payload['updated_at'] = $now;
        Capsule::table('mod_opennfse_distribuicao_dfe')
            ->where('id', (int) $existing->id)
            ->update($payload);
    }

    public function listDistributedDocumentsByMonth(string $environment, string $month): array
    {
        $query = Capsule::table('mod_opennfse_distribuicao_dfe')
            ->where('environment', $this->normalizeEnvironment($environment));

        $this->applyMonthFilter($query, $month);

        $rows = [];
        foreach ($query
            ->orderBy('referencia_em', 'desc')
            ->orderBy('nsu', 'desc')
            ->get() as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    public function summaryDistributedDocumentsByMonth(string $environment, string $month): array
    {
        $query = Capsule::table('mod_opennfse_distribuicao_dfe')
            ->where('environment', $this->normalizeEnvironment($environment))
            ->select([
                Capsule::raw('COUNT(*) as total_docs'),
                Capsule::raw("SUM(CASE WHEN tipo_documento = 'EMISSAO' THEN 1 ELSE 0 END) as total_emissoes"),
                Capsule::raw("SUM(CASE WHEN tipo_documento = 'CANCELAMENTO' THEN 1 ELSE 0 END) as total_cancelamentos"),
                Capsule::raw("SUM(CASE WHEN tipo_documento NOT IN ('EMISSAO','CANCELAMENTO') THEN 1 ELSE 0 END) as total_outros"),
            ]);

        $this->applyMonthFilter($query, $month);

        $row = $query->first();
        $data = $row ? (array) $row : [];

        return [
            'total_docs' => (int) ($data['total_docs'] ?? 0),
            'total_emissoes' => (int) ($data['total_emissoes'] ?? 0),
            'total_cancelamentos' => (int) ($data['total_cancelamentos'] ?? 0),
            'total_outros' => (int) ($data['total_outros'] ?? 0),
        ];
    }

    private function applyMonthFilter($query, string $month): void
    {
        $month = trim($month);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return;
        }

        try {
            $start = new \DateTimeImmutable($month . '-01 00:00:00');
            $end = $start->modify('last day of this month')->setTime(23, 59, 59);
            $query->where('referencia_em', '>=', $start->format('Y-m-d H:i:s'))
                ->where('referencia_em', '<=', $end->format('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
        }
    }

    private function normalizeEnvironment(string $environment): string
    {
        $environment = trim($environment);
        return $environment !== '' ? $environment : 'producao';
    }

    private function normalizeDocType(string $type): string
    {
        $type = strtoupper(trim($type));
        return in_array($type, ['EMISSAO', 'CANCELAMENTO', 'DESCONHECIDO'], true) ? $type : 'DESCONHECIDO';
    }

    private function nullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function nullableDigits($value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return $digits !== '' ? $digits : null;
    }

    private function nullableDate($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00') {
            return null;
        }

        return substr($value, 0, 10);
    }

    private function nullableDateTime($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return $value;
    }
}
