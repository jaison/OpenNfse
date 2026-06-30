<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Api\NfsePhpSdkAdapter;
use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Helpers\CorrelationIdGenerator;
use OpenNfse\Helpers\NfseXmlExtractor;
use OpenNfse\Module;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\SequenceRepository;
use OpenNfse\Repositories\WhmcsCustomerRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;

final class NfseService
{
    public function emitir(int $invoiceId, ?string $correlationId = null): void
    {
        $this->ensureMigrated();
        $correlationId = $correlationId ?: CorrelationIdGenerator::generate($invoiceId);

        $config = (new ConfigRepository())->get();
        if (empty($config)) {
            throw new NfseModuleException('Configuração do módulo não encontrada.');
        }

        $invoiceRepo = new WhmcsInvoiceRepository();
        $customerRepo = new WhmcsCustomerRepository();
        $notaRepo = new NotaRepository();
        $logRepo = new LogRepository();

        $invoice = $invoiceRepo->getInvoice($invoiceId);
        $eligibility = (new EmissionEligibilityService())->check($invoice);
        if ($eligibility !== null) {
            switch ($eligibility['reason']) {
                case EmissionEligibilityService::SKIP_NOT_PAID:
                    $logRepo->insert(
                        null,
                        'EMISSAO_SKIP_NOT_PAID',
                        json_encode(['invoiceid' => $invoiceId, 'status' => $eligibility['status']], JSON_UNESCAPED_UNICODE),
                        null,
                        $correlationId
                    );
                    throw new NfseModuleException('A emissão só pode ser solicitada quando a fatura estiver como Paid.');
                case EmissionEligibilityService::SKIP_CREDIT_PAYMENT:
                    $logRepo->insert(
                        null,
                        'EMISSAO_SKIP_CREDIT_PAYMENT',
                        json_encode(['invoiceid' => $invoiceId, 'paymentmethod' => $eligibility['paymentMethod'], 'credit' => $eligibility['credit']], JSON_UNESCAPED_UNICODE),
                        null,
                        $correlationId
                    );
                    throw new NfseModuleException('A emissão está desativada quando a fatura é paga com crédito.');
                case EmissionEligibilityService::SKIP_GATEWAY_DISABLED:
                    $logRepo->insert(
                        null,
                        'EMISSAO_SKIP_GATEWAY_DISABLED',
                        json_encode(['invoiceid' => $invoiceId, 'paymentmethod' => $eligibility['paymentMethod']], JSON_UNESCAPED_UNICODE),
                        null,
                        $correlationId
                    );
                    throw new NfseModuleException('Emissão desativada para o gateway de pagamento desta fatura.');
            }
        }
        $items = $invoiceRepo->getItems($invoiceId);
        $userId = (int) ($invoice['userid'] ?? 0);
        $client = $customerRepo->getClient($userId);

        try {
            (new EmissionPreValidationService())->validate($config, $invoice, $items, $client);
        } catch (\Throwable $e) {
            (new InvoiceHistoryService())->append($invoiceId, 'Emissão da NFS-e bloqueada na validação preventiva. ' . $e->getMessage());
            throw $e;
        }

        $country = strtoupper(trim((string) ($client['country'] ?? '')));
        if ($country === '') {
            throw new NfseModuleException('País do tomador não informado.');
        }

        $tomadorCpfCnpj = '';
        if ($country === 'BR') {
            $tomadorFieldId = (int) ($config['tomador_cpfcnpj_customfield_id'] ?? 0);
            if ($tomadorFieldId <= 0) {
                throw new NfseModuleException('Custom Field ID do CPF/CNPJ do tomador não configurado.');
            }
            $tomadorCpfCnpj = $customerRepo->getCpfCnpjFromCustomField($userId, $tomadorFieldId);
        }

        $numeroDps = (new SequenceRepository())->next(
            (string) $config['environment'],
            (string) $config['cnpj_emissor'],
            (string) $config['serie_dps']
        );

        $dps = (new DpsBuilderService())->build($config, $invoice, $items, $client, $tomadorCpfCnpj, $numeroDps);

        $idDps = property_exists($dps, 'infDps') && $dps->infDps && property_exists($dps->infDps, 'id')
            ? (string) $dps->infDps->id
            : null;

        $notaRepo->upsert([
            'invoiceid' => $invoiceId,
            'userid' => $userId,
            'id_dps' => $idDps,
            'protocolo' => $idDps,
            'numero_nf' => null,
            'competencia' => null,
            'chave_acesso' => null,
            'xml_path' => null,
            'status' => 'PROCESSANDO',
            'erro_api' => null,
        ]);

        $nota = $notaRepo->findByInvoiceId($invoiceId);
        $notaId = $nota ? (int) $nota['id'] : null;

        $requestPayload = $this->safeSerializeDps($dps);
        $requestPayload['_configSnapshot'] = [
            'prestador_informar_im' => $config['prestador_informar_im'] ?? null,
            'inscricao_municipal' => $config['inscricao_municipal'] ?? null,
        ];
        if (class_exists(\Nfse\Xml\DpsXmlBuilder::class) && $dps instanceof \Nfse\Dto\Nfse\DpsData) {
            $requestPayload['_dpsXml'] = (new \Nfse\Xml\DpsXmlBuilder())->build($dps);
        }
        $logRepo->insert($notaId, 'EMISSAO_REQUEST', json_encode($requestPayload, JSON_UNESCAPED_UNICODE), null, $correlationId);

        $sdkConfig = $this->buildSdkConfig($config);
        $adapter = new NfsePhpSdkAdapter();
        $result = $adapter->emitir($sdkConfig, $dps);

        if (!$result->success) {
            $status = $result->errorType === 'tech' ? 'ERRO' : 'REJEITADA';
            $notaRepo->upsert([
                'invoiceid' => $invoiceId,
                'userid' => $userId,
                'status' => $status,
                'erro_api' => $result->errorMessage,
            ]);
            $logRepo->insert($notaId, 'EMISSAO_RESPONSE', null, $result->rawResponse ?? $result->errorMessage, $correlationId);
            (new InvoiceHistoryService())->append($invoiceId, 'Emissão da NFS-e finalizada com status ' . $status . '. ' . trim((string) $result->errorMessage));
            return;
        }

        $xmlPath = null;
        $numeroNf = null;
        $competencia = null;
        $emitidaEm = null;
        if ($result->nfseXml) {
            $numeroNf = NfseXmlExtractor::extractNumeroNfse($result->nfseXml);
            $competencia = NfseXmlExtractor::extractCompetencia($result->nfseXml);
            $emitidaEm = NfseXmlExtractor::extractEmitidaEm($result->nfseXml);
            $xmlPath = (new StorageService())->saveXml(
                $invoiceId,
                $result->nfseXml,
                $numeroNf,
                $emitidaEm,
                (string) ($config['environment'] ?? ''),
                (string) ($config['serie_dps'] ?? '')
            );
        }

        $notaRepo->upsert([
            'invoiceid' => $invoiceId,
            'userid' => $userId,
            'status' => 'EMITIDA',
            'protocolo' => $result->idDps,
            'chave_acesso' => $result->chaveAcesso,
            'xml_path' => $xmlPath,
            'numero_nf' => $numeroNf,
            'competencia' => $competencia,
            'emitida_em' => $emitidaEm,
            'erro_api' => null,
        ]);

        $logRepo->insert($notaId, 'EMISSAO_RESPONSE', null, $result->nfseXml ?? 'OK', $correlationId);
        $historyMessage = 'NFS-e emitida com sucesso.';
        if ($numeroNf !== null && trim($numeroNf) !== '') {
            $historyMessage .= ' Número: ' . trim($numeroNf) . '.';
        }
        if ($result->chaveAcesso !== null && trim((string) $result->chaveAcesso) !== '') {
            $historyMessage .= ' Chave: ' . trim((string) $result->chaveAcesso) . '.';
        }
        (new InvoiceHistoryService())->append($invoiceId, $historyMessage);
    }

    public function consultarStatus(int $invoiceId, ?string $correlationId = null): void
    {
        $this->ensureMigrated();
        $correlationId = $correlationId ?: CorrelationIdGenerator::generate($invoiceId);

        $config = (new ConfigRepository())->get();
        if (empty($config)) {
            throw new NfseModuleException('Configuração do módulo não encontrada.');
        }

        $notaRepo = new NotaRepository();
        $logRepo = new LogRepository();
        $nota = $notaRepo->findByInvoiceId($invoiceId);
        if (!$nota) {
            throw new NfseModuleException('Nota não encontrada para esta fatura.');
        }
        $notaId = (int) $nota['id'];
        $statusBefore = (string) ($nota['status'] ?? '');
        $errorBefore = (string) ($nota['erro_api'] ?? '');

        $sdkConfig = $this->buildSdkConfig($config);
        $adapter = new NfsePhpSdkAdapter();

        $chave = $nota['chave_acesso'] ?? null;
        if ($chave) {
            $resp = $adapter->consultarNfse($sdkConfig, (string) $chave);
            $logRepo->insert($notaId, 'CONSULTA_NFSE', json_encode(['chaveAcesso' => $chave], JSON_UNESCAPED_UNICODE), $resp->nfseXml ?? $resp->errorMessage, $correlationId);
            if ($resp->found && $resp->nfseXml) {
                $numeroNf = NfseXmlExtractor::extractNumeroNfse($resp->nfseXml);
                $competencia = NfseXmlExtractor::extractCompetencia($resp->nfseXml);
                $emitidaEm = NfseXmlExtractor::extractEmitidaEm($resp->nfseXml);
                $xmlPath = (new StorageService())->saveXml(
                    $invoiceId,
                    $resp->nfseXml,
                    $numeroNf,
                    $emitidaEm,
                    (string) ($config['environment'] ?? ''),
                    (string) ($config['serie_dps'] ?? '')
                );
                $update = [
                    'invoiceid' => $invoiceId,
                    'userid' => (int) $nota['userid'],
                    'status' => (string) ($nota['status'] ?? '') === 'CANCELADA' ? 'CANCELADA' : 'EMITIDA',
                    'xml_path' => $xmlPath,
                    'numero_nf' => $numeroNf,
                    'competencia' => $competencia,
                ];
                if ((string) ($nota['emitida_em'] ?? '') === '') {
                    if ($emitidaEm !== null) {
                        $update['emitida_em'] = $emitidaEm;
                    }
                }
                $notaRepo->upsert($update);
                $statusAfter = (string) ($update['status'] ?? $statusBefore);
                if ($statusAfter !== $statusBefore) {
                    (new InvoiceHistoryService())->append($invoiceId, 'Consulta de status atualizou a NFS-e para ' . $statusAfter . '.');
                }
            } elseif ($resp->errorMessage) {
                $notaRepo->upsert([
                    'invoiceid' => $invoiceId,
                    'userid' => (int) $nota['userid'],
                    'status' => 'ERRO',
                    'erro_api' => $resp->errorMessage,
                ]);
                if ($statusBefore !== 'ERRO' || $errorBefore !== (string) $resp->errorMessage) {
                    (new InvoiceHistoryService())->append($invoiceId, 'Consulta de status retornou erro: ' . (string) $resp->errorMessage);
                }
            }
            return;
        }

        $idDps = $nota['id_dps'] ?? null;
        if (!$idDps) {
            throw new NfseModuleException('Nota sem chave e sem ID DPS para consulta.');
        }

        $resp = $adapter->consultarDps($sdkConfig, (string) $idDps);
        $logRepo->insert(
            $notaId,
            'CONSULTA_DPS',
            json_encode(['idDps' => $idDps], JSON_UNESCAPED_UNICODE),
            json_encode(['chaveAcesso' => $resp->chaveAcesso, 'erro' => $resp->errorMessage], JSON_UNESCAPED_UNICODE),
            $correlationId
        );

        if ($resp->found && $resp->chaveAcesso) {
            $update = [
                'invoiceid' => $invoiceId,
                'userid' => (int) $nota['userid'],
                'chave_acesso' => $resp->chaveAcesso,
            ];
            if ((string) ($nota['status'] ?? '') !== 'EMITIDA') {
                $update['status'] = 'PROCESSANDO';
            }
            $notaRepo->upsert($update);
            if ($statusBefore !== 'PROCESSANDO') {
                (new InvoiceHistoryService())->append($invoiceId, 'Consulta de status localizou a chave da NFS-e e manteve a nota em processamento.');
            }
        } elseif ($resp->errorMessage) {
            $notaRepo->upsert([
                'invoiceid' => $invoiceId,
                'userid' => (int) $nota['userid'],
                'status' => 'ERRO',
                'erro_api' => $resp->errorMessage,
            ]);
            if ($statusBefore !== 'ERRO' || $errorBefore !== (string) $resp->errorMessage) {
                (new InvoiceHistoryService())->append($invoiceId, 'Consulta de status retornou erro: ' . (string) $resp->errorMessage);
            }
        }
    }

    public function cancelarNfse(int $invoiceId, string $codigoMotivo, string $motivo, string $descricao, ?string $correlationId = null): void
    {
        $this->ensureMigrated();
        $correlationId = $correlationId ?: CorrelationIdGenerator::generate($invoiceId);

        $codigoMotivo = trim($codigoMotivo);
        if ($codigoMotivo === '3') {
            $codigoMotivo = '9';
        }
        if (!in_array($codigoMotivo, ['1', '2', '9'], true)) {
            throw new NfseModuleException('Código do motivo de cancelamento inválido.');
        }

        $config = (new ConfigRepository())->get();
        if (empty($config)) {
            throw new NfseModuleException('Configuração do módulo não encontrada.');
        }

        $notaRepo = new NotaRepository();
        $logRepo = new LogRepository();
        $nota = $notaRepo->findByInvoiceId($invoiceId);
        if (!$nota) {
            throw new NfseModuleException('Nota não encontrada para esta fatura.');
        }
        $notaId = (int) $nota['id'];

        if ((string) ($nota['status'] ?? '') !== 'EMITIDA') {
            throw new NfseModuleException('Cancelamento disponível apenas quando o status está EMITIDA.');
        }

        $chave = (string) ($nota['chave_acesso'] ?? '');
        if ($chave === '') {
            throw new NfseModuleException('Chave de acesso não encontrada para cancelamento.');
        }

        $cnpjAutor = preg_replace('/\D/', '', (string) ($config['cnpj_emissor'] ?? ''));
        if (!$cnpjAutor || strlen($cnpjAutor) !== 14) {
            throw new NfseModuleException('CNPJ do emissor inválido para cancelamento.');
        }

        $tpAmb = ($config['environment'] ?? 'homologacao') === 'producao' ? 1 : 2;

        if (!class_exists(\Nfse\Dto\Nfse\PedRegEventoData::class)) {
            throw new NfseModuleException('SDK nfse-nacional/nfse-php não encontrada para cancelamento.');
        }

        $xDesc = 'Cancelamento de NFS-e';
        $xMotivo = trim($descricao);
        if ($xMotivo === '') {
            $xMotivo = trim($motivo);
        } elseif (trim($motivo) !== '') {
            $xMotivo = trim($motivo) . ' - ' . $xMotivo;
        }
        if (mb_strlen($xMotivo, 'UTF-8') < 15) {
            throw new NfseModuleException('Motivo do cancelamento inválido (mínimo 15 caracteres).');
        }
        if (mb_strlen($xMotivo, 'UTF-8') > 255) {
            throw new NfseModuleException('Motivo do cancelamento inválido (máximo 255 caracteres).');
        }

        $evento = new \Nfse\Dto\Nfse\PedRegEventoData([
            'versao' => '1.01',
            'infPedReg' => [
                'tpAmb' => $tpAmb,
                'verAplic' => 'WHMCS-NFSE-ADDON',
                'dhEvento' => date('c'),
                'chNFSe' => $chave,
                'cnpjAutor' => $cnpjAutor,
                'tipoEvento' => '101101',
                'e101101' => [
                    'xDesc' => $xDesc,
                    'cMotivo' => $codigoMotivo,
                    'xMotivo' => $xMotivo,
                ],
            ],
        ]);

        $payload = [
            'invoiceid' => $invoiceId,
            'chaveAcesso' => $chave,
            'cMotivo' => $codigoMotivo,
            'xMotivo' => $xMotivo,
            'xDesc' => $xDesc,
        ];
        if (class_exists(\Nfse\Xml\EventosXmlBuilder::class)) {
            $payload['_eventoXml'] = (new \Nfse\Xml\EventosXmlBuilder())->buildPedRegEvento($evento);
        }
        $logRepo->insert($notaId, 'CANCELAMENTO_REQUEST', json_encode($payload, JSON_UNESCAPED_UNICODE), null, $correlationId);

        $sdkConfig = $this->buildSdkConfig($config);
        $adapter = new NfsePhpSdkAdapter();
        $result = $adapter->cancelarNfse($sdkConfig, $evento);

        if (!$result->success) {
            $notaRepo->upsert([
                'invoiceid' => $invoiceId,
                'userid' => (int) $nota['userid'],
                'cancelado_em' => null,
                'cancel_codigo_motivo' => $codigoMotivo,
                'cancel_motivo' => $xMotivo,
                'cancel_descricao' => $xDesc,
                'cancel_erro' => $result->errorMessage,
            ]);
            $logRepo->insert($notaId, 'CANCELAMENTO_RESPONSE', null, $result->rawResponse ?? $result->errorMessage, $correlationId);
            (new InvoiceHistoryService())->append($invoiceId, 'Falha ao cancelar a NFS-e. Motivo: ' . trim((string) $result->errorMessage));
            throw new NfseModuleException('Falha ao cancelar NFS-e: ' . $result->errorMessage);
        }

        $notaRepo->upsert([
            'invoiceid' => $invoiceId,
            'userid' => (int) $nota['userid'],
            'status' => 'CANCELADA',
            'cancelado_em' => date('Y-m-d H:i:s'),
            'cancel_codigo_motivo' => $codigoMotivo,
            'cancel_motivo' => $xMotivo,
            'cancel_descricao' => $xDesc,
            'cancel_erro' => null,
        ]);
        $logRepo->insert($notaId, 'CANCELAMENTO_RESPONSE', null, $result->eventoXmlGZipB64 ?? 'OK', $correlationId);
        (new InvoiceHistoryService())->append($invoiceId, 'NFS-e cancelada com sucesso. Motivo: ' . $xMotivo);
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

    private function safeSerializeDps(object $dps): array
    {
        if (method_exists($dps, 'toArray')) {
            return (array) $dps->toArray();
        }
        return json_decode(json_encode($dps, JSON_UNESCAPED_UNICODE), true) ?: [];
    }

    private function ensureMigrated(): void
    {
        Module::migrator()->up();
    }
}
