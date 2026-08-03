<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;

final class DanfsePdfService
{
    public function generatePdfBytes(int $invoiceId): string
    {
        if ($invoiceId <= 0) {
            throw new NfseModuleException('Fatura inválida.');
        }

        $notaRepo = new NotaRepository();
        $logRepo = new LogRepository();
        $nota = $notaRepo->findByInvoiceId($invoiceId);
        if (!$nota) {
            throw new NfseModuleException('NFS-e não encontrada para esta fatura.');
        }

        if ((string) ($nota['status'] ?? '') !== 'EMITIDA') {
            throw new NfseModuleException('NFS-e ainda não está disponível para PDF.');
        }

        $xmlPath = (string) ($nota['xml_path'] ?? '');
        if ($xmlPath === '') {
            throw new NfseModuleException('XML não disponível para gerar PDF.');
        }

        $storage = new StorageService();
        if (!empty($nota['pdf_path']) || !empty($nota['pdf_gerado_em'])) {
            $notaRepo->upsert([
                'invoiceid' => $invoiceId,
                'userid' => (int) ($nota['userid'] ?? 0),
                'pdf_path' => null,
                'pdf_gerado_em' => null,
            ]);
        }

        try {
            $xmlAbsPath = $storage->resolveAbsolutePath($xmlPath);
        } catch (\Throwable $e) {
            $fallback = $storage->findLatestXmlAbsolutePath($invoiceId);
            if (!$fallback) {
                throw $e;
            }

            $xmlAbsPath = $fallback;
            $rel = $storage->relativePathFromAbsolute($xmlAbsPath);
            if ($rel !== null) {
                $notaRepo->upsert([
                    'invoiceid' => $invoiceId,
                    'userid' => (int) ($nota['userid'] ?? 0),
                    'xml_path' => $rel,
                ]);
            }
        }

        $notaId = (int) ($nota['id'] ?? 0);
        $logRepo->insert($notaId > 0 ? $notaId : null, 'DANFSE_PDF_REQUEST', json_encode(['invoiceid' => $invoiceId, 'xml_path' => $xmlPath], JSON_UNESCAPED_UNICODE), null);

        try {
            $generator = new LocalNfsePdfGenerator();
            $danfseConfig = $this->getDanfseConfig();
            if (!empty($danfseConfig['logoSvg']) && method_exists($generator, 'setLogoSvg')) {
                $generator->setLogoSvg((string) $danfseConfig['logoSvg']);
            }
            if (method_exists($generator, 'setHeaderInfo')) {
                $generator->setHeaderInfo([
                    'municipalityLine' => $danfseConfig['municipioNome'] ?? null,
                    'secretariatLine' => $danfseConfig['secretariaNome'] ?? null,
                    'phoneLine' => $danfseConfig['telefone'] ?? null,
                    'emailLine' => $danfseConfig['email'] ?? null,
                ]);
            }
            if (method_exists($generator, 'setHomologationWarning')) {
                $generator->setHomologationWarning(((string) ($danfseConfig['environment'] ?? 'homologacao')) === 'homologacao');
            }
            $pdf = $generator->parseXml($xmlAbsPath)->generate();
            $pdfBytes = (string) $pdf->Output('', 'S');
        } catch (\Throwable $e) {
            $logRepo->insert($notaId > 0 ? $notaId : null, 'DANFSE_PDF_ERROR', null, $e->getMessage());
            throw new NfseModuleException('Falha ao gerar PDF da NFS-e: ' . $e->getMessage());
        }

        $pdfLen = strlen($pdfBytes);
        if ($pdfLen <= 0) {
            $logRepo->insert($notaId > 0 ? $notaId : null, 'DANFSE_PDF_ERROR', null, 'PDF vazio');
            throw new NfseModuleException('Falha ao gerar PDF da NFS-e: PDF vazio.');
        }

        $logRepo->insert($notaId > 0 ? $notaId : null, 'DANFSE_PDF_RESPONSE', null, json_encode(['pdf_storage' => 'on_demand', 'pdf_len' => $pdfLen], JSON_UNESCAPED_UNICODE));

        return $pdfBytes;
    }

    public function getDownloadFilename(int $invoiceId): string
    {
        if ($invoiceId <= 0) {
            return 'danfse_invoice.pdf';
        }

        $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
        $invoiceNumber = (string) $invoiceId;

        try {
            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
            $invoiceNumber = trim((string) ($invoice['invoicenum'] ?? ''));
            if ($invoiceNumber === '') {
                $invoiceNumber = (string) $invoiceId;
            }
        } catch (\Throwable $e) {
            $invoiceNumber = (string) $invoiceId;
        }

        $numeroNf = trim((string) ($nota['numero_nf'] ?? ''));
        $emitidaEm = trim((string) ($nota['emitida_em'] ?? ''));

        if ($numeroNf !== '') {
            $dataEmissao = $emitidaEm !== '' ? date('mY', strtotime($emitidaEm) ?: time()) : date('mY');

            return sprintf(
                'danfse_%s_fatura%s_%s.pdf',
                preg_replace('/[^A-Za-z0-9_-]/', '_', $numeroNf),
                preg_replace('/[^A-Za-z0-9_-]/', '_', $invoiceNumber),
                preg_replace('/[^A-Za-z0-9_-]/', '_', $dataEmissao)
            );
        }

        return 'danfse_invoice_' . $invoiceId . '.pdf';
    }

    private function getDanfseConfig(): array
    {
        $config = (new ConfigRepository())->get();
        $logoSvg = trim((string) ($config['danfse_logo_svg'] ?? ''));
        if ($logoSvg === '') {
            $defaultLogoPath = dirname(__DIR__, 3) . '/assets/brasao_itajai.svg';
            if (is_file($defaultLogoPath)) {
                $svg = file_get_contents($defaultLogoPath);
                if (is_string($svg) && trim($svg) !== '') {
                    $logoSvg = $svg;
                }
            }
        }

        return [
            'environment' => (string) ($config['environment'] ?? 'homologacao'),
            'logoSvg' => $logoSvg,
            'municipioNome' => trim((string) ($config['danfse_municipio_nome'] ?? '')) ?: 'MUNICÍPIO DE ITAJAÍ',
            'secretariaNome' => trim((string) ($config['danfse_secretaria_nome'] ?? '')) ?: 'SECRETARIA MUNICIPAL DA FAZENDA',
            'telefone' => trim((string) ($config['danfse_telefone'] ?? '')) ?: '(47)3241-7400',
            'email' => trim((string) ($config['danfse_email'] ?? '')) ?: 'plantaofiscal@itajai.sc.gov.br',
        ];
    }
}
