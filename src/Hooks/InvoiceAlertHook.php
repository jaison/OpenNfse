<?php

declare(strict_types=1);

namespace OpenNfse\Hooks;

use OpenNfse\Helpers\NfseXmlExtractor;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use OpenNfse\Services\AlertService;
use OpenNfse\Services\InvoiceAlertPolicy;
use OpenNfse\Services\StorageService;

/**
 * Observa eventos da fatura e abre alertas quando ela já tem NFS-e emitida.
 *
 * Nada aqui cancela nota, bloqueia emissão ou interrompe o fluxo do WHMCS:
 * qualquer erro vai só para o log (NFSE_ALERTA_ERRO).
 */
final class InvoiceAlertHook
{
    private const MAX_XML_BYTES = 5242880;

    public function onCancelled(array $vars): void
    {
        $this->run('InvoiceCancelled', $vars, static function (array $nota): ?array {
            return InvoiceAlertPolicy::onCancelled($nota);
        });
    }

    public function onRefunded(array $vars): void
    {
        $this->run('InvoiceRefunded', $vars, static function (array $nota): ?array {
            return InvoiceAlertPolicy::onRefunded($nota);
        });
    }

    public function onUnpaid(array $vars): void
    {
        $this->run('InvoiceUnpaid', $vars, static function (array $nota, int $invoiceId): ?array {
            $invoiceRepo = new WhmcsInvoiceRepository();
            $invoice = $invoiceRepo->getInvoice($invoiceId);
            $credit = (float) str_replace(',', '.', (string) ($invoice['credit'] ?? 0));

            return InvoiceAlertPolicy::onUnpaid($nota, $invoiceRepo->sumPayments($invoiceId), $credit);
        });
    }

    public function onTotalChanged(array $vars): void
    {
        $this->run('UpdateInvoiceTotal', $vars, function (array $nota, int $invoiceId): ?array {
            $notaValue = $this->resolveNotaValue($invoiceId, $nota);
            if ($notaValue === null) {
                $this->log($nota, 'NFSE_ALERTA_SEM_VALOR', [
                    'invoiceid' => $invoiceId,
                    'hook' => 'UpdateInvoiceTotal',
                ], 'Valor da NFS-e não encontrado no XML; alteração da fatura não conferida.');
                return null;
            }

            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);

            return InvoiceAlertPolicy::onTotalChanged($nota, $notaValue, $invoice);
        });
    }

    public function onPaid(array $vars): void
    {
        $this->run('InvoicePaid', $vars, static function (array $nota, int $invoiceId): ?array {
            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);

            return InvoiceAlertPolicy::onPaid($nota, $invoice);
        });
    }

    /**
     * @param callable(array, int): ?array $decide
     */
    private function run(string $hook, array $vars, callable $decide): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }

        $nota = null;
        try {
            // Consulta barata (índice único por fatura): a maioria das faturas não tem nota emitida.
            $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
            if (!InvoiceAlertPolicy::hasEmittedNota($nota)) {
                return;
            }

            $decision = $decide($nota, $invoiceId);
            if ($decision === null) {
                return;
            }

            (new AlertService())->raise($invoiceId, (string) $decision['tipo'], $nota, (string) $decision['detalhes']);
        } catch (\Throwable $e) {
            $this->log($nota, 'NFSE_ALERTA_ERRO', ['invoiceid' => $invoiceId, 'hook' => $hook], $e->getMessage());
        }
    }

    private function resolveNotaValue(int $invoiceId, array $nota): ?float
    {
        $storage = new StorageService();
        $path = null;
        $xmlPath = trim((string) ($nota['xml_path'] ?? ''));
        if ($xmlPath !== '') {
            try {
                $path = $storage->resolveAbsolutePath($xmlPath);
            } catch (\Throwable $e) {
                $path = null;
            }
        }
        if ($path === null || !is_file($path)) {
            $path = $storage->findLatestXmlAbsolutePath($invoiceId, $nota);
        }
        if ($path === null || !is_file($path)) {
            return null;
        }

        $size = @filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_XML_BYTES) {
            return null;
        }

        $xml = @file_get_contents($path);
        if (!is_string($xml) || $xml === '') {
            return null;
        }

        return NfseXmlExtractor::extractValorServico($xml);
    }

    private function log(?array $nota, string $tipo, array $payload, ?string $response): void
    {
        try {
            $notaId = (int) ($nota['id'] ?? 0);
            (new LogRepository())->insert(
                $notaId > 0 ? $notaId : null,
                $tipo,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $response
            );
        } catch (\Throwable $e) {
            return;
        }
    }
}
