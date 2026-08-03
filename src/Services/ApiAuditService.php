<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Api\NfsePhpSdkAdapter;
use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Helpers\NfseXmlExtractor;
use OpenNfse\Module;
use OpenNfse\Repositories\ApiAuditRepository;
use OpenNfse\Repositories\ConfigRepository;

final class ApiAuditService
{
    public function syncByNsu(int $maxBatches = 10): array
    {
        Module::migrator()->up();

        $config = (new ConfigRepository())->get();
        if (empty($config['certificate_path']) || empty($config['certificate_password_enc'])) {
            throw new NfseModuleException('Configure o certificado digital antes de sincronizar a distribuicao da API.');
        }

        $environment = trim((string) ($config['environment'] ?? 'producao'));
        $cnpjConsulta = preg_replace('/\D+/', '', (string) ($config['cnpj_emissor'] ?? ''));
        $sdkConfig = $this->buildSdkConfig($config);
        $repo = new ApiAuditRepository();
        $state = $repo->getSyncState($environment);
        $adapter = new NfsePhpSdkAdapter();
        $initialNsu = max(0, (int) ($state['ultimo_nsu'] ?? 0));

        $withCnpj = $this->runSyncMode(
            $adapter,
            $sdkConfig,
            $repo,
            $environment,
            $initialNsu,
            $cnpjConsulta !== '' ? $cnpjConsulta : null,
            'com_cnpj',
            $maxBatches,
            true
        );

        $withoutCnpj = null;
        $selected = $withCnpj;
        if (($withCnpj['processed_count'] ?? 0) <= 0 && (int) ($withCnpj['ultimo_nsu'] ?? $initialNsu) === $initialNsu) {
            $withoutCnpj = $this->runSyncMode(
                $adapter,
                $sdkConfig,
                $repo,
                $environment,
                $initialNsu,
                null,
                'sem_cnpj',
                $maxBatches,
                true
            );

            if (($withoutCnpj['processed_count'] ?? 0) > ($selected['processed_count'] ?? 0)
                || (int) ($withoutCnpj['ultimo_nsu'] ?? 0) > (int) ($selected['ultimo_nsu'] ?? 0)
                || (int) ($withoutCnpj['maior_nsu'] ?? 0) > (int) ($selected['maior_nsu'] ?? 0)) {
                $selected = $withoutCnpj;
            }
        }

        $diagnostics = [
            'tested_at' => date('Y-m-d H:i:s'),
            'initial_nsu' => $initialNsu,
            'with_cnpj' => $this->buildDiagnosticSummary($withCnpj),
            'without_cnpj' => $withoutCnpj !== null ? $this->buildDiagnosticSummary($withoutCnpj) : null,
        ];

        $repo->upsertSyncState([
            'environment' => $environment,
            'cnpj_consulta' => (string) ($selected['cnpj_consulta'] ?? $cnpjConsulta),
            'ultimo_nsu' => (int) ($selected['ultimo_nsu'] ?? $initialNsu),
            'maior_nsu' => isset($selected['maior_nsu']) ? (int) $selected['maior_nsu'] : null,
            'ultimo_sync_em' => date('Y-m-d H:i:s'),
            'last_sync_mode' => (string) ($selected['mode'] ?? 'com_cnpj'),
            'last_diagnostics_json' => json_encode($diagnostics, JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'environment' => $environment,
            'cnpj_consulta' => (string) ($selected['cnpj_consulta'] ?? $cnpjConsulta),
            'ultimo_nsu' => (int) ($selected['ultimo_nsu'] ?? $initialNsu),
            'maior_nsu' => isset($selected['maior_nsu']) ? (int) $selected['maior_nsu'] : null,
            'processed_count' => (int) ($selected['processed_count'] ?? 0),
            'batch_count' => (int) ($selected['batch_count'] ?? 0),
            'alertas' => (array) ($selected['alertas'] ?? []),
            'errors' => (array) ($selected['errors'] ?? []),
            'mode' => (string) ($selected['mode'] ?? 'com_cnpj'),
            'diagnostics' => $diagnostics,
        ];
    }

    private function runSyncMode(
        NfsePhpSdkAdapter $adapter,
        array $sdkConfig,
        ApiAuditRepository $repo,
        string $environment,
        int $initialNsu,
        ?string $cnpjConsulta,
        string $mode,
        int $maxBatches,
        bool $persistDocuments
    ): array {
        $currentNsu = $initialNsu;
        $maiorNsu = null;
        $processedCount = 0;
        $batchCount = 0;
        $lastErrors = [];
        $lastAlerts = [];
        $lastRawResponse = null;
        $emptyResponseCount = 0;

        for ($index = 0; $index < max(1, $maxBatches); $index++) {
            $response = $adapter->baixarDfe($sdkConfig, $currentNsu, $cnpjConsulta, true);
            $batchCount++;
            $lastRawResponse = $this->summarizeRawResponse($response['raw_response'] ?? null);

            if (($response['success'] ?? false) !== true) {
                return [
                    'mode' => $mode,
                    'cnpj_consulta' => $cnpjConsulta,
                    'ultimo_nsu' => $currentNsu,
                    'maior_nsu' => $maiorNsu,
                    'processed_count' => $processedCount,
                    'batch_count' => $batchCount,
                    'alertas' => (array) ($response['alertas'] ?? []),
                    'errors' => (array) ($response['errors'] ?? []),
                    'error_message' => (string) ($response['error_message'] ?? 'erro desconhecido'),
                    'raw_response_summary' => $lastRawResponse,
                    'empty_response_count' => $emptyResponseCount,
                ];
            }

            $items = (array) ($response['items'] ?? []);
            if (empty($items)) {
                $emptyResponseCount++;
            }
            $lastErrors = (array) ($response['errors'] ?? []);
            $lastAlerts = (array) ($response['alertas'] ?? []);

            foreach ($items as $item) {
                $nsu = max(0, (int) ($item['nsu'] ?? 0));
                if ($nsu <= 0) {
                    continue;
                }

                $currentNsu = max($currentNsu, $nsu);
                if ($persistDocuments) {
                    $repo->upsertDistributedDocument($this->buildDistributedDocumentPayload(
                        $environment,
                        $nsu,
                        (string) ($item['chave_acesso'] ?? ''),
                        (string) ($item['dfe_xml_gzip_b64'] ?? '')
                    ));
                }
                $processedCount++;
            }

            if (isset($response['ultimo_nsu']) && (int) $response['ultimo_nsu'] > $currentNsu) {
                $currentNsu = (int) $response['ultimo_nsu'];
            }
            if (isset($response['maior_nsu']) && $response['maior_nsu'] !== null) {
                $maiorNsu = max((int) $response['maior_nsu'], $maiorNsu ?? 0);
            }

            if (empty($items) || ($maiorNsu !== null && $currentNsu >= $maiorNsu)) {
                break;
            }
        }

        return [
            'mode' => $mode,
            'cnpj_consulta' => $cnpjConsulta,
            'ultimo_nsu' => $currentNsu,
            'maior_nsu' => $maiorNsu,
            'processed_count' => $processedCount,
            'batch_count' => $batchCount,
            'alertas' => $lastAlerts,
            'errors' => $lastErrors,
            'error_message' => null,
            'raw_response_summary' => $lastRawResponse,
            'empty_response_count' => $emptyResponseCount,
        ];
    }

    private function buildDistributedDocumentPayload(string $environment, int $nsu, string $chaveAcesso, string $payload): array
    {
        $xml = $this->decodeDistribuicaoPayload($payload);
        $eventType = NfseXmlExtractor::extractEventType($xml);
        $emitidaEm = NfseXmlExtractor::extractEmitidaEm($xml);
        $eventoEm = NfseXmlExtractor::extractEventDate($xml);
        $numeroNf = NfseXmlExtractor::extractNumeroNfse($xml);
        $chaveExtraida = NfseXmlExtractor::extractChaveAcesso($xml);
        $competencia = NfseXmlExtractor::extractCompetencia($xml);

        $tipoDocumento = 'DESCONHECIDO';
        if ($eventType !== null && $eventType !== '') {
            $tipoDocumento = 'CANCELAMENTO';
        } elseif ($numeroNf !== null && $numeroNf !== '') {
            $tipoDocumento = 'EMISSAO';
        }

        $referenceDate = $eventoEm ?: ($emitidaEm ?: ($competencia !== null ? $competencia . ' 00:00:00' : date('Y-m-d H:i:s')));

        return [
            'environment' => $environment,
            'nsu' => $nsu,
            'chave_acesso' => $chaveAcesso !== '' ? $chaveAcesso : ($chaveExtraida ?? ''),
            'tipo_documento' => $tipoDocumento,
            'tipo_evento' => $eventType,
            'numero_nf' => $numeroNf,
            'competencia' => $competencia,
            'emitida_em' => $emitidaEm,
            'evento_em' => $eventoEm,
            'referencia_em' => $referenceDate,
            'xml_hash' => $xml !== '' ? sha1($xml) : null,
        ];
    }

    private function decodeDistribuicaoPayload(string $payload): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }

        if (strpos($payload, '<') === 0) {
            return $payload;
        }

        $binary = base64_decode($payload, true);
        if ($binary === false || $binary === '') {
            return '';
        }

        $candidates = [$binary];
        if (function_exists('gzdecode')) {
            $decoded = @gzdecode($binary);
            if (is_string($decoded) && $decoded !== '') {
                $candidates[] = $decoded;
            }
        }
        if (function_exists('gzuncompress')) {
            $decoded = @gzuncompress($binary);
            if (is_string($decoded) && $decoded !== '') {
                $candidates[] = $decoded;
            }
        }
        if (function_exists('gzinflate')) {
            $decoded = @gzinflate($binary);
            if (is_string($decoded) && $decoded !== '') {
                $candidates[] = $decoded;
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && strpos($candidate, '<') === 0) {
                return $candidate;
            }
        }

        return '';
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

    private function buildDiagnosticSummary(array $result): array
    {
        return [
            'mode' => (string) ($result['mode'] ?? ''),
            'cnpj_consulta' => $result['cnpj_consulta'] ?? null,
            'ultimo_nsu' => (int) ($result['ultimo_nsu'] ?? 0),
            'maior_nsu' => isset($result['maior_nsu']) && $result['maior_nsu'] !== null ? (int) $result['maior_nsu'] : null,
            'processed_count' => (int) ($result['processed_count'] ?? 0),
            'batch_count' => (int) ($result['batch_count'] ?? 0),
            'empty_response_count' => (int) ($result['empty_response_count'] ?? 0),
            'error_message' => $result['error_message'] ?? null,
            'alertas' => array_slice((array) ($result['alertas'] ?? []), 0, 5),
            'errors' => array_slice((array) ($result['errors'] ?? []), 0, 5),
            'raw_response_summary' => $result['raw_response_summary'] ?? null,
        ];
    }

    private function summarizeRawResponse($rawResponse): ?string
    {
        if ($rawResponse === null) {
            return null;
        }

        if (is_scalar($rawResponse)) {
            $summary = trim((string) $rawResponse);
            return $summary !== '' ? mb_substr($summary, 0, 1000) : null;
        }

        $json = json_encode($rawResponse, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || trim($json) === '') {
            return null;
        }

        return mb_substr($json, 0, 1000);
    }
}
