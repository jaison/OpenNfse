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
        $currentNsu = max(0, (int) ($state['ultimo_nsu'] ?? 0));
        $maiorNsu = isset($state['maior_nsu']) ? (int) $state['maior_nsu'] : null;
        $processedCount = 0;
        $batchCount = 0;
        $lastErrors = [];
        $lastAlerts = [];

        $adapter = new NfsePhpSdkAdapter();

        for ($index = 0; $index < max(1, $maxBatches); $index++) {
            $response = $adapter->baixarDfe($sdkConfig, $currentNsu, $cnpjConsulta !== '' ? $cnpjConsulta : null, true);
            $batchCount++;

            if (($response['success'] ?? false) !== true) {
                throw new NfseModuleException('Falha ao sincronizar distribuicao por NSU: ' . (string) ($response['error_message'] ?? 'erro desconhecido'));
            }

            $items = (array) ($response['items'] ?? []);
            $lastErrors = (array) ($response['errors'] ?? []);
            $lastAlerts = (array) ($response['alertas'] ?? []);

            foreach ($items as $item) {
                $nsu = max(0, (int) ($item['nsu'] ?? 0));
                if ($nsu <= 0) {
                    continue;
                }

                $currentNsu = max($currentNsu, $nsu);
                $repo->upsertDistributedDocument($this->buildDistributedDocumentPayload(
                    $environment,
                    $nsu,
                    (string) ($item['chave_acesso'] ?? ''),
                    (string) ($item['dfe_xml_gzip_b64'] ?? '')
                ));
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

        $repo->upsertSyncState([
            'environment' => $environment,
            'cnpj_consulta' => $cnpjConsulta,
            'ultimo_nsu' => $currentNsu,
            'maior_nsu' => $maiorNsu,
            'ultimo_sync_em' => date('Y-m-d H:i:s'),
        ]);

        return [
            'environment' => $environment,
            'cnpj_consulta' => $cnpjConsulta,
            'ultimo_nsu' => $currentNsu,
            'maior_nsu' => $maiorNsu,
            'processed_count' => $processedCount,
            'batch_count' => $batchCount,
            'alertas' => $lastAlerts,
            'errors' => $lastErrors,
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
}
