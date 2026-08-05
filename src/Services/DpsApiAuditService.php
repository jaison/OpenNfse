<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Api\NfsePhpSdkAdapter;
use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Module;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\DpsApiAuditRepository;
use OpenNfse\Repositories\FiscalHistoryRepository;
use OpenNfse\Repositories\NotaRepository;
use WHMCS\Database\Capsule;

final class DpsApiAuditService
{
    private const DESCRIPTION = 'Esta auditoria existe para conferir se as DPS geradas localmente foram reconhecidas pela API e se a sequência numérica do emissor/série permaneceu íntegra, sem lacunas não explicadas.';

    public function createRun(string $month): array
    {
        Module::migrator()->up();
        (new FiscalHistoryRepository())->backfillCurrentStateIfNeeded();

        $month = $this->normalizeMonth($month);
        $this->getValidatedConfig();

        $repo = new DpsApiAuditRepository();
        $latestRun = $repo->findLatestRunByMonth($month);
        if ($latestRun !== null && in_array((string) ($latestRun['status'] ?? ''), ['pending', 'running'], true)) {
            return $latestRun;
        }

        $runId = $repo->createRun([
            'audit_month' => $month,
            'status' => 'pending',
            'total_items' => $this->countLocalEmissionRows($month),
            'description' => self::DESCRIPTION,
        ]);

        $run = $repo->findRun($runId);
        if ($run === null) {
            throw new NfseModuleException('Falha ao criar a execução da auditoria DPS.');
        }

        return $run;
    }

    public function processRunBatch(int $runId, int $batchSize = 25): array
    {
        Module::migrator()->up();
        (new FiscalHistoryRepository())->backfillCurrentStateIfNeeded();

        $config = $this->getValidatedConfig();
        $repo = new DpsApiAuditRepository();
        $run = $repo->findRun($runId);
        if ($run === null) {
            throw new NfseModuleException('Execução da auditoria DPS não encontrada.');
        }
        if (in_array((string) ($run['status'] ?? ''), ['completed', 'failed'], true)) {
            return $this->buildRunPayload($run, false);
        }
        try {
            $repo->updateRun($runId, [
                'status' => 'running',
                'started_at' => $run['started_at'] ?? date('Y-m-d H:i:s'),
            ]);
            $run['status'] = 'running';
            if (empty($run['started_at'])) {
                $run['started_at'] = date('Y-m-d H:i:s');
            }

            $month = (string) ($run['audit_month'] ?? '');
            $rows = $this->listLocalEmissionRows($month, max(1, $batchSize), (int) ($run['last_processed_history_id'] ?? 0));
            $sdkConfig = $this->buildSdkConfig($config);
            $adapter = new NfsePhpSdkAdapter();
            $lastProcessedHistoryId = (int) ($run['last_processed_history_id'] ?? 0);

            foreach ($rows as $row) {
                try {
                    $result = $this->auditSingleRow($row, $adapter, $sdkConfig);
                } catch (\Throwable $e) {
                    $detailedError = $this->formatThrowable($e);
                    $result = [
                        'api_found' => false,
                        'api_chave_acesso' => null,
                        'api_error' => $detailedError,
                        'audit_status' => 'ERRO_API',
                        'audit_message' => 'Falha ao consultar esta DPS na API: ' . $this->summarizeThrowable($e),
                    ];
                }

                $repo->upsertResult(array_merge($result, [
                    'run_id' => $runId,
                    'result_type' => 'DPS',
                    'history_id' => (int) ($row['id'] ?? 0),
                    'invoiceid' => (int) ($row['invoiceid'] ?? 0),
                    'userid' => (int) ($row['userid'] ?? 0),
                    'event_date' => ($row['emitida_em'] ?? '') !== '' ? $row['emitida_em'] : ($row['created_at'] ?? null),
                    'id_dps' => $row['id_dps'] ?? null,
                    'numero_dps' => $row['numero_dps'] ?? null,
                    'numero_nf' => $row['numero_nf'] ?? null,
                    'local_chave_acesso' => $row['chave_acesso'] ?? null,
                ]));
                $this->incrementRunCounter($run, (string) ($result['audit_status'] ?? 'SEM_ID_DPS'));
                $lastProcessedHistoryId = max($lastProcessedHistoryId, (int) ($row['id'] ?? 0));
            }

            $run['processed_items'] = min((int) ($run['total_items'] ?? 0), (int) ($run['processed_items'] ?? 0) + count($rows));
            $run['last_processed_history_id'] = $lastProcessedHistoryId;

            if (empty($rows) || (int) $run['processed_items'] >= (int) ($run['total_items'] ?? 0)) {
                $sequenceAudit = $this->buildSequenceAudit($this->listLocalEmissionRows($month), $config);
                $repo->clearGapResults($runId);
                foreach ((array) ($sequenceAudit['missing_rows'] ?? []) as $gap) {
                    $repo->upsertResult([
                        'run_id' => $runId,
                        'result_type' => 'GAP',
                        'numero_dps' => $gap['numero_dps'] ?? null,
                        'id_dps' => $gap['id_dps'] ?? null,
                        'audit_status' => 'GAP',
                        'audit_message' => $gap['message'] ?? null,
                        'evidence_classification' => $gap['classification'] ?? null,
                        'evidence_count' => $gap['evidence_count'] ?? 0,
                    ]);
                }

                $run['gap_count'] = (int) ($sequenceAudit['missing_count'] ?? 0);
                $run['first_number'] = $sequenceAudit['first_number'] ?? null;
                $run['last_number'] = $sequenceAudit['last_number'] ?? null;
                $run['current_sequence_last_number'] = $sequenceAudit['current_sequence_last_number'] ?? null;
                $run['status'] = 'completed';
                $run['finished_at'] = date('Y-m-d H:i:s');
            }

            $repo->updateRun($runId, [
                'status' => $run['status'],
                'processed_items' => (int) ($run['processed_items'] ?? 0),
                'ok_count' => (int) ($run['ok_count'] ?? 0),
                'local_sem_chave_count' => (int) ($run['local_sem_chave_count'] ?? 0),
                'sem_chave_api_count' => (int) ($run['sem_chave_api_count'] ?? 0),
                'nao_encontrada_count' => (int) ($run['nao_encontrada_count'] ?? 0),
                'chave_divergente_count' => (int) ($run['chave_divergente_count'] ?? 0),
                'erro_api_count' => (int) ($run['erro_api_count'] ?? 0),
                'sem_id_dps_count' => (int) ($run['sem_id_dps_count'] ?? 0),
                'gap_count' => (int) ($run['gap_count'] ?? 0),
                'first_number' => $run['first_number'] ?? null,
                'last_number' => $run['last_number'] ?? null,
                'current_sequence_last_number' => $run['current_sequence_last_number'] ?? null,
                'last_processed_history_id' => (int) ($run['last_processed_history_id'] ?? 0),
                'finished_at' => $run['finished_at'] ?? null,
                'last_error' => null,
            ]);

            $updatedRun = $repo->findRun($runId);
            if ($updatedRun === null) {
                throw new NfseModuleException('Falha ao atualizar a execução da auditoria DPS.');
            }

            return $this->buildRunPayload($updatedRun, count($rows) > 0);
        } catch (\Throwable $e) {
            $errorDetail = $this->formatThrowable($e);
            $repo->updateRun($runId, [
                'status' => 'running',
                'started_at' => $run['started_at'] ?? date('Y-m-d H:i:s'),
                'last_error' => $errorDetail,
            ]);
            throw new NfseModuleException('Falha ao processar lote da auditoria DPS. ' . $errorDetail);
        }
    }

    public function getRun(int $runId): ?array
    {
        Module::migrator()->up();
        return (new DpsApiAuditRepository())->findRun($runId);
    }

    public function getRunPayload(int $runId): ?array
    {
        $run = $this->getRun($runId);
        return $run !== null ? $this->buildRunPayload($run, false) : null;
    }

    private function countLocalEmissionRows(string $month): int
    {
        $query = Capsule::table('mod_opennfse_notas_history as h')
            ->select(['h.id'])
            ->where('h.tipo_registro', 'EMISSAO');
        $this->applyMonthFilter($query, $month);
        return (int) $query->count('h.id');
    }

    private function listLocalEmissionRows(string $month, ?int $limit = null, int $afterHistoryId = 0): array
    {
        $query = Capsule::table('mod_opennfse_notas_history as h')
            ->join('tblclients as c', 'c.id', '=', 'h.userid')
            ->leftJoin('tblinvoices as i', 'i.id', '=', 'h.invoiceid')
            ->leftJoin('mod_opennfse_notas as n', 'n.invoiceid', '=', 'h.invoiceid')
            ->select([
                'h.id',
                'h.invoiceid',
                'h.userid',
                'h.id_dps',
                'h.numero_nf',
                Capsule::raw("COALESCE(NULLIF(h.chave_acesso, ''), NULLIF(n.chave_acesso, '')) as chave_acesso"),
                'h.emitida_em',
                'h.created_at',
                'h.origem',
                'h.status_fiscal',
                'i.total as invoice_total',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->where('h.tipo_registro', 'EMISSAO');
        if ($afterHistoryId > 0) {
            $query->where('h.id', '>', $afterHistoryId);
        }
        $this->applyMonthFilter($query, $month);

        $rows = [];
        foreach ($query
            ->orderBy('h.id', 'asc')
            ->limit($limit ?? 1000000)
            ->get() as $row) {
            $rowArr = (array) $row;
            $rowArr['numero_dps'] = $this->extractDpsSequenceNumber((string) ($rowArr['id_dps'] ?? ''));
            $rows[] = $rowArr;
        }

        return $rows;
    }

    private function buildSequenceAudit(array $localRows, array $config): array
    {
        $sequenceNumbers = [];
        foreach ($localRows as $row) {
            $seq = $this->extractDpsSequenceNumber((string) ($row['id_dps'] ?? ''));
            if ($seq !== null) {
                $sequenceNumbers[$seq] = true;
            }
        }

        if (empty($sequenceNumbers)) {
            return [
                'first_number' => null,
                'last_number' => null,
                'missing_count' => 0,
                'missing_rows' => [],
                'current_sequence_last_number' => $this->getCurrentSequenceLastNumber($config),
            ];
        }

        $numbers = array_keys($sequenceNumbers);
        sort($numbers, SORT_NUMERIC);
        $firstNumber = (int) reset($numbers);
        $lastNumber = (int) end($numbers);
        $missingRows = [];

        for ($number = $firstNumber; $number <= $lastNumber; $number++) {
            if (isset($sequenceNumbers[$number])) {
                continue;
            }

            $missingIdDps = $this->buildExpectedIdDps($config, $number);
            $evidence = $this->findSequenceGapEvidence($missingIdDps);
            $missingRows[] = [
                'numero_dps' => $number,
                'id_dps' => $missingIdDps,
                'classification' => $evidence['classification'],
                'message' => $evidence['message'],
                'evidence_count' => $evidence['evidence_count'],
            ];
        }

        return [
            'first_number' => $firstNumber,
            'last_number' => $lastNumber,
            'missing_count' => count($missingRows),
            'missing_rows' => $missingRows,
            'current_sequence_last_number' => $this->getCurrentSequenceLastNumber($config),
        ];
    }

    private function findSequenceGapEvidence(string $idDps): array
    {
        if ($idDps === '') {
            return [
                'classification' => 'SEM_REFERENCIA',
                'message' => 'Não foi possível reconstruir o ID DPS desta posição.',
                'evidence_count' => 0,
            ];
        }

        $historyAny = Capsule::table('mod_opennfse_notas_history')
            ->where('id_dps', $idDps)
            ->count('id');
        if ($historyAny > 0) {
            return [
                'classification' => 'FORA_DO_PERIODO',
                'message' => 'A DPS existe no histórico, mas fora do período auditado.',
                'evidence_count' => $historyAny,
            ];
        }

        $currentNota = Capsule::table('mod_opennfse_notas')
            ->where('id_dps', $idDps)
            ->count('id');
        if ($currentNota > 0) {
            return [
                'classification' => 'ESTADO_ATUAL',
                'message' => 'A DPS existe no estado atual, mas não apareceu como emissão neste período do histórico.',
                'evidence_count' => $currentNota,
            ];
        }

        $logEvidence = Capsule::table('mod_opennfse_logs')
            ->where(static function ($query) use ($idDps): void {
                $query->where('request', 'like', '%' . $idDps . '%')
                    ->orWhere('response', 'like', '%' . $idDps . '%');
            })
            ->count('id');
        if ($logEvidence > 0) {
            return [
                'classification' => 'EVIDENCIA_LOG',
                'message' => 'Há evidências técnicas desta DPS nos logs, mas sem emissão consolidada no histórico do período.',
                'evidence_count' => $logEvidence,
            ];
        }

        return [
            'classification' => 'SEM_EVIDENCIA',
            'message' => 'Nenhuma evidência local foi encontrada para esta lacuna de sequência.',
            'evidence_count' => 0,
        ];
    }

    private function getCurrentSequenceLastNumber(array $config): ?int
    {
        $environment = trim((string) ($config['environment'] ?? ''));
        $cnpjEmissor = preg_replace('/\D+/', '', (string) ($config['cnpj_emissor'] ?? ''));
        $serieDps = trim((string) ($config['serie_dps'] ?? ''));
        if ($environment === '' || $cnpjEmissor === '' || $serieDps === '') {
            return null;
        }

        $row = Capsule::table('mod_opennfse_sequences')
            ->where('environment', $environment)
            ->where('cnpj_emissor', $cnpjEmissor)
            ->where('serie_dps', $serieDps)
            ->first();

        return $row !== null ? (int) ($row->last_number ?? 0) : null;
    }

    private function buildExpectedIdDps(array $config, int $number): string
    {
        $cnpjEmissor = preg_replace('/\D+/', '', (string) ($config['cnpj_emissor'] ?? ''));
        $codigoIbge = trim((string) ($config['codigo_ibge'] ?? ''));
        $serieDps = trim((string) ($config['serie_dps'] ?? ''));
        if ($cnpjEmissor === '' || $codigoIbge === '' || $serieDps === '') {
            return '';
        }

        if (class_exists(\Nfse\Support\IdGenerator::class)) {
            return \Nfse\Support\IdGenerator::generateDpsId($cnpjEmissor, $codigoIbge, $serieDps, $number);
        }

        return '';
    }

    private function extractDpsSequenceNumber(string $idDps): ?int
    {
        $idDps = trim($idDps);
        if ($idDps === '') {
            return null;
        }

        $digits = substr($idDps, -15);
        if (preg_match('/^\d{15}$/', $digits) !== 1) {
            return null;
        }

        return (int) ltrim($digits, '0') ?: 0;
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
            $query->whereRaw('COALESCE(h.emitida_em, h.created_at) >= ?', [$start->format('Y-m-d H:i:s')]);
            $query->whereRaw('COALESCE(h.emitida_em, h.created_at) <= ?', [$end->format('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
        }
    }

    private function buildSdkConfig(array $config): array
    {
        $crypto = new CryptoService();
        $password = $crypto->decrypt((string) $config['certificate_password_enc']);

        $ambiente = ($config['environment'] ?? 'homologacao') === 'producao'
            ? \Nfse\Enums\TipoAmbiente::Producao
            : \Nfse\Enums\TipoAmbiente::Homologacao;

        return [
            'ambiente' => $ambiente,
            'certificatePath' => (string) $config['certificate_path'],
            'certificatePassword' => $password,
            'codigoMunicipio' => (string) ($config['codigo_ibge'] ?? null),
        ];
    }

    private function getValidatedConfig(): array
    {
        $config = (new ConfigRepository())->get();
        if (empty($config)) {
            throw new NfseModuleException('Configuração do módulo não encontrada.');
        }
        if (empty($config['certificate_path']) || empty($config['certificate_password_enc'])) {
            throw new NfseModuleException('Configure o certificado digital antes de executar a auditoria API por DPS.');
        }

        return $config;
    }

    private function normalizeMonth(string $month): string
    {
        $month = trim($month);
        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return $month;
        }

        return date('Y-m');
    }

    private function auditSingleRow(array $row, NfsePhpSdkAdapter $adapter, array $sdkConfig): array
    {
        $idDps = trim((string) ($row['id_dps'] ?? ''));
        $localChave = trim((string) ($row['chave_acesso'] ?? ''));
        if ($idDps === '') {
            return [
                'api_found' => false,
                'api_chave_acesso' => null,
                'api_error' => null,
                'audit_status' => 'SEM_ID_DPS',
                'audit_message' => 'Registro local sem ID DPS para consulta.',
            ];
        }

        $resp = $adapter->consultarDps($sdkConfig, $idDps);
        $apiChave = trim((string) ($resp->chaveAcesso ?? ''));
        if ($resp->errorMessage !== null && trim((string) $resp->errorMessage) !== '') {
            return [
                'api_found' => $resp->found,
                'api_chave_acesso' => $apiChave !== '' ? $apiChave : null,
                'api_error' => trim((string) $resp->errorMessage),
                'audit_status' => 'ERRO_API',
                'audit_message' => trim((string) $resp->errorMessage),
            ];
        }
        if (!$resp->found) {
            return [
                'api_found' => false,
                'api_chave_acesso' => null,
                'api_error' => null,
                'audit_status' => 'NAO_ENCONTRADA',
                'audit_message' => 'A API não localizou esta DPS.',
            ];
        }
        if ($apiChave === '') {
            return [
                'api_found' => true,
                'api_chave_acesso' => null,
                'api_error' => null,
                'audit_status' => 'SEM_CHAVE_API',
                'audit_message' => 'A API localizou a DPS, mas ainda não retornou chave de acesso.',
            ];
        }
        if ($localChave === '') {
            return [
                'api_found' => true,
                'api_chave_acesso' => $apiChave,
                'api_error' => null,
                'audit_status' => 'LOCAL_SEM_CHAVE',
                'audit_message' => 'A API retornou chave de acesso, mas o histórico local ainda está sem chave.',
            ];
        }
        if ($localChave !== $apiChave) {
            $contextualResult = $this->classifyDivergentKeyContext($row, $apiChave);
            if ($contextualResult !== null) {
                return array_merge([
                    'api_found' => true,
                    'api_chave_acesso' => $apiChave,
                    'api_error' => null,
                ], $contextualResult);
            }

            return [
                'api_found' => true,
                'api_chave_acesso' => $apiChave,
                'api_error' => null,
                'audit_status' => 'CHAVE_DIVERGENTE',
                'audit_message' => 'A chave retornada pela API diverge da chave registrada localmente, sem contexto fiscal suficiente para explicar a diferença.',
            ];
        }

        return [
            'api_found' => true,
            'api_chave_acesso' => $apiChave,
            'api_error' => null,
            'audit_status' => 'OK',
            'audit_message' => 'DPS localizada na API com chave coerente.',
        ];
    }

    private function incrementRunCounter(array &$run, string $status): void
    {
        $map = [
            'OK' => 'ok_count',
            'DIVERGENCIA_CANCELADA' => 'ok_count',
            'DIVERGENCIA_REEMITIDA' => 'ok_count',
            'LOCAL_SEM_CHAVE' => 'local_sem_chave_count',
            'SEM_CHAVE_API' => 'sem_chave_api_count',
            'NAO_ENCONTRADA' => 'nao_encontrada_count',
            'CHAVE_DIVERGENTE' => 'chave_divergente_count',
            'ERRO_API' => 'erro_api_count',
            'SEM_ID_DPS' => 'sem_id_dps_count',
        ];
        $field = $map[$status] ?? null;
        if ($field !== null) {
            $run[$field] = (int) ($run[$field] ?? 0) + 1;
        }
    }

    private function buildRunPayload(array $run, bool $processedThisCall): array
    {
        $totalItems = (int) ($run['total_items'] ?? 0);
        $processedItems = (int) ($run['processed_items'] ?? 0);
        return [
            'run_id' => (int) ($run['id'] ?? 0),
            'status' => (string) ($run['status'] ?? 'pending'),
            'total_items' => $totalItems,
            'processed_items' => $processedItems,
            'progress_percent' => $totalItems > 0 ? round(($processedItems / max(1, $totalItems)) * 100, 1) : 100.0,
            'processed_this_call' => $processedThisCall,
            'finished' => in_array((string) ($run['status'] ?? ''), ['completed', 'failed'], true),
            'last_error' => $this->normalizeText($run['last_error'] ?? null),
        ];
    }

    private function summarizeThrowable(\Throwable $e, int $maxLength = 220): string
    {
        $summary = trim($e->getMessage());
        if ($summary === '') {
            $summary = 'Erro sem mensagem retornada pela camada de consulta.';
        }

        $summary = get_class($e) . ': ' . $summary;
        if (mb_strlen($summary) > $maxLength) {
            return rtrim(mb_substr($summary, 0, $maxLength - 3)) . '...';
        }

        return $summary;
    }

    private function formatThrowable(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            $message = 'Erro sem mensagem retornada pela camada de consulta.';
        }

        return sprintf(
            '%s: %s [%s:%d]',
            get_class($e),
            $message,
            basename($e->getFile()),
            (int) $e->getLine()
        );
    }

    private function normalizeText($value): ?string
    {
        $text = trim((string) $value);
        return $text !== '' ? $text : null;
    }

    private function classifyDivergentKeyContext(array $row, string $apiChave): ?array
    {
        $invoiceId = (int) ($row['invoiceid'] ?? 0);
        $historyId = (int) ($row['id'] ?? 0);
        $localChave = trim((string) ($row['chave_acesso'] ?? ''));
        if ($invoiceId <= 0 || $localChave === '') {
            return null;
        }

        $historyRepo = new FiscalHistoryRepository();
        $localCancelled = $historyRepo->hasCancellationByChave($localChave);
        $apiCancelled = $historyRepo->hasCancellationByChave($apiChave);
        $matchingApiEmission = $this->findEmissionByInvoiceAndChave($invoiceId, $apiChave, $historyId);
        $matchingLocalEmission = $this->findEmissionByInvoiceAndChave($invoiceId, $localChave, $historyId);
        $currentNota = (new NotaRepository())->findByInvoiceId($invoiceId);
        $currentChave = trim((string) ($currentNota['chave_acesso'] ?? ''));
        $currentStatus = strtoupper(trim((string) ($currentNota['status'] ?? '')));
        $hasCurrentReemission = $currentStatus === 'EMITIDA'
            && $currentChave !== ''
            && $currentChave !== $apiChave
            && ($currentChave === $localChave || $matchingLocalEmission !== null);

        if (($apiCancelled || $localCancelled) && ($matchingApiEmission !== null || $hasCurrentReemission)) {
            return [
                'audit_status' => 'DIVERGENCIA_REEMITIDA',
                'audit_message' => 'A divergência está explicada por cancelamento e reemissão da mesma fatura: uma das chaves já foi cancelada e há evidência de nova emissão posterior.',
                'evidence_classification' => 'REEMISSAO_POSTERIOR',
                'evidence_count' => 1,
            ];
        }

        if ($apiCancelled && $hasCurrentReemission) {
            return [
                'audit_status' => 'DIVERGENCIA_REEMITIDA',
                'audit_message' => 'A chave retornada pela API pertence a uma emissão já cancelada, enquanto a fatura mantém uma nova emissão vigente com outra chave.',
                'evidence_classification' => 'REEMISSAO_POSTERIOR',
                'evidence_count' => 1,
            ];
        }

        if ($localCancelled) {
            return [
                'audit_status' => 'DIVERGENCIA_CANCELADA',
                'audit_message' => 'A chave local divergente já possui cancelamento registrado no histórico fiscal, indicando evento posterior à emissão original.',
                'evidence_classification' => 'EMISSAO_CANCELADA',
                'evidence_count' => 1,
            ];
        }

        if ($apiCancelled) {
            return [
                'audit_status' => 'DIVERGENCIA_CANCELADA',
                'audit_message' => 'A chave retornada pela API já possui cancelamento registrado no histórico fiscal, indicando que esta DPS se refere a uma emissão posteriormente cancelada.',
                'evidence_classification' => 'EMISSAO_CANCELADA',
                'evidence_count' => 1,
            ];
        }

        if ($matchingApiEmission !== null) {
            return [
                'audit_status' => 'DIVERGENCIA_REEMITIDA',
                'audit_message' => 'A chave retornada pela API coincide com outra emissão registrada para a mesma fatura, sugerindo reemissão posterior.',
                'evidence_classification' => 'REEMISSAO_POSTERIOR',
                'evidence_count' => 1,
            ];
        }

        if ($hasCurrentReemission) {
            return [
                'audit_status' => 'DIVERGENCIA_REEMITIDA',
                'audit_message' => 'A fatura já possui uma emissão vigente com chave diferente da retornada para esta DPS, o que sugere reemissão posterior após cancelamento do documento anterior.',
                'evidence_classification' => 'REEMISSAO_POSTERIOR',
                'evidence_count' => 1,
            ];
        }

        return null;
    }

    private function findEmissionByInvoiceAndChave(int $invoiceId, string $chaveAcesso, int $excludeHistoryId = 0): ?array
    {
        $chaveAcesso = trim($chaveAcesso);
        if ($invoiceId <= 0 || $chaveAcesso === '') {
            return null;
        }

        $query = Capsule::table('mod_opennfse_notas_history')
            ->where('tipo_registro', 'EMISSAO')
            ->where('invoiceid', $invoiceId)
            ->where('chave_acesso', $chaveAcesso);

        if ($excludeHistoryId > 0) {
            $query->where('id', '<>', $excludeHistoryId);
        }

        $row = $query
            ->orderByRaw('COALESCE(emitida_em, created_at) DESC')
            ->orderBy('id', 'desc')
            ->first();

        return $row !== null ? (array) $row : null;
    }
}
