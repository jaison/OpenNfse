<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Helpers\CorrelationIdGenerator;
use OpenNfse\Migrations\Migrator;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\QueueRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;

final class QueueService
{
    private const MAX_TENTATIVAS = 5;

    public function enqueueEmit(int $invoiceId, string $tipoLog): void
    {
        (new Migrator())->up();
        $correlationId = CorrelationIdGenerator::generate($invoiceId);
        $repo = new QueueRepository();
        $repo->enqueue($invoiceId, $correlationId);
        (new LogRepository())->insert(null, $tipoLog, json_encode(['invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
    }

    public function processBatch(int $limit = 10): void
    {
        (new Migrator())->up();
        $config = (new ConfigRepository())->get();
        if (empty($config)) {
            return;
        }

        if ((string) ($config['queue_enabled'] ?? '0') !== '1') {
            return;
        }

        $waitInterval = (int) ($config['queue_wait_status_interval_seconds'] ?? 120);
        if ($waitInterval < 30) {
            $waitInterval = 30;
        }
        if ($waitInterval > 3600) {
            $waitInterval = 3600;
        }

        $this->processEmissaoBatch($limit, $waitInterval);
        $this->processStatusBatch($limit, $waitInterval);
    }

    private function processEmissaoBatch(int $limit, int $waitIntervalSeconds): void
    {
        $queueRepo = new QueueRepository();
        $jobs = $queueRepo->claimNext($limit);
        if (empty($jobs)) {
            return;
        }

        $nfseService = new NfseService();
        $notaRepo = new NotaRepository();
        $logRepo = new LogRepository();
        $invoiceRepo = new WhmcsInvoiceRepository();
        $eligibilityChecker = new EmissionEligibilityService();
        $errorClassifier = new QueueErrorClassifierService();

        foreach ($jobs as $job) {
            $id = (int) ($job['id'] ?? 0);
            $invoiceId = (int) ($job['invoiceid'] ?? 0);
            $tentativas = (int) ($job['tentativas'] ?? 0);
            $correlationId = trim((string) ($job['correlation_id'] ?? ''));
            if ($correlationId === '') {
                $correlationId = CorrelationIdGenerator::generate($invoiceId);
            }

            if ($id <= 0 || $invoiceId <= 0) {
                continue;
            }

            try {
                $notaBefore = $notaRepo->findByInvoiceId($invoiceId);
                $statusBefore = $notaBefore ? (string) ($notaBefore['status'] ?? '') : '';
                if ($statusBefore === 'EMITIDA') {
                    $queueRepo->markDone($id);
                    $logRepo->insert(null, 'QUEUE_SKIP_ALREADY_EMITIDA', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
                    continue;
                }
                if ($statusBefore === 'PROCESSANDO') {
                    $queueRepo->markWaitStatus($id, $waitIntervalSeconds);
                    $logRepo->insert(null, 'QUEUE_WAIT_STATUS', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
                    continue;
                }

                $invoice = $invoiceRepo->getInvoice($invoiceId);
                $eligibility = $eligibilityChecker->check($invoice);
                if ($eligibility !== null) {
                    $queueRepo->markDone($id);
                    switch ($eligibility['reason']) {
                        case EmissionEligibilityService::SKIP_NOT_PAID:
                            $logRepo->insert(
                                null,
                                'QUEUE_SKIP_NOT_PAID',
                                json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId, 'status' => $eligibility['status']], JSON_UNESCAPED_UNICODE),
                                null,
                                $correlationId
                            );
                            break;
                        case EmissionEligibilityService::SKIP_CREDIT_PAYMENT:
                            $logRepo->insert(
                                null,
                                'QUEUE_SKIP_CREDIT_PAYMENT',
                                json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId, 'paymentmethod' => $eligibility['paymentMethod'], 'credit' => $eligibility['credit']], JSON_UNESCAPED_UNICODE),
                                null,
                                $correlationId
                            );
                            break;
                        case EmissionEligibilityService::SKIP_GATEWAY_DISABLED:
                            $logRepo->insert(
                                null,
                                'QUEUE_SKIP_GATEWAY_DISABLED',
                                json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId, 'paymentmethod' => $eligibility['paymentMethod']], JSON_UNESCAPED_UNICODE),
                                null,
                                $correlationId
                            );
                            break;
                    }
                    continue;
                }

                $nfseService->emitir($invoiceId, $correlationId);
                $nota = $notaRepo->findByInvoiceId($invoiceId);
                $status = $nota ? (string) ($nota['status'] ?? '') : '';
                if ($status === 'EMITIDA') {
                    $queueRepo->markDone($id);
                    $logRepo->insert(null, 'QUEUE_DONE', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
                } elseif ($status === 'PROCESSANDO') {
                    $queueRepo->markWaitStatus($id, $waitIntervalSeconds);
                    $logRepo->insert(null, 'QUEUE_WAIT_STATUS', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
                } else {
                    $err = $nota ? (string) ($nota['erro_api'] ?? '') : '';
                    $queueRepo->markError($id, $err !== '' ? $err : ('Status final: ' . $status));
                    $logRepo->insert(null, 'QUEUE_ERROR', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), $err !== '' ? $err : ('Status final: ' . $status), $correlationId);
                }
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (!$errorClassifier->isRetryable($e) || $tentativas >= self::MAX_TENTATIVAS) {
                    $queueRepo->markError($id, $msg);
                    $logRepo->insert(null, 'QUEUE_ERROR', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), $msg, $correlationId);
                } else {
                    $queueRepo->markRetry($id, $msg);
                    $logRepo->insert(null, 'QUEUE_RETRY', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), $msg, $correlationId);
                }
            }
        }
    }

    private function processStatusBatch(int $limit, int $waitIntervalSeconds): void
    {
        $queueRepo = new QueueRepository();
        $jobs = $queueRepo->claimNextStatus($limit);
        if (empty($jobs)) {
            return;
        }

        $nfseService = new NfseService();
        $notaRepo = new NotaRepository();
        $logRepo = new LogRepository();
        $errorClassifier = new QueueErrorClassifierService();

        foreach ($jobs as $job) {
            $id = (int) ($job['id'] ?? 0);
            $invoiceId = (int) ($job['invoiceid'] ?? 0);
            $checks = (int) ($job['status_checks'] ?? 0);
            $correlationId = trim((string) ($job['correlation_id'] ?? ''));
            if ($correlationId === '') {
                $correlationId = CorrelationIdGenerator::generate($invoiceId);
            }

            if ($id <= 0 || $invoiceId <= 0) {
                continue;
            }

            $factorPow = min($checks, 5);
            $nextInterval = $waitIntervalSeconds * (2 ** $factorPow);
            if ($nextInterval > 3600) {
                $nextInterval = 3600;
            }

            try {
                $nfseService->consultarStatus($invoiceId, $correlationId);
                $nota = $notaRepo->findByInvoiceId($invoiceId);
                $status = $nota ? (string) ($nota['status'] ?? '') : '';
                if ($status === 'EMITIDA') {
                    $queueRepo->markDone($id);
                    $logRepo->insert(null, 'QUEUE_DONE', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
                } elseif ($status === 'PROCESSANDO') {
                    $queueRepo->touchWaitStatus($id, $nextInterval);
                } else {
                    $err = $nota ? (string) ($nota['erro_api'] ?? '') : '';
                    $queueRepo->markError($id, $err !== '' ? $err : ('Status final: ' . $status));
                    $logRepo->insert(null, 'QUEUE_ERROR', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), $err !== '' ? $err : ('Status final: ' . $status), $correlationId);
                }
            } catch (\Throwable $e) {
                if ($errorClassifier->isRetryable($e)) {
                    $queueRepo->touchWaitStatus($id, $nextInterval, $e->getMessage());
                } else {
                    $queueRepo->markError($id, $e->getMessage());
                    $logRepo->insert(null, 'QUEUE_ERROR', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), $e->getMessage(), $correlationId);
                }
            }
        }
    }
}
