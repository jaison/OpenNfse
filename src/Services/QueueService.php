<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Helpers\CorrelationIdGenerator;
use OpenNfse\Migrations\Migrator;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\QueueRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;

final class QueueService
{
    private const MAX_TENTATIVAS = QueueStatePolicy::MAX_TENTATIVAS;

    public function enqueueEmit(int $invoiceId, string $tipoLog, ?bool $allowUnpaid = null, ?string $origem = null): bool
    {
        (new Migrator())->up();
        $correlationId = CorrelationIdGenerator::generate($invoiceId);
        $repo = new QueueRepository();

        // Mesmo lock da emissão: evita dois itens ativos para a fatura em pedidos simultâneos.
        $lock = new InvoiceLockService();
        if (!$lock->acquire($invoiceId)) {
            throw new NfseModuleException(InvoiceLockService::BUSY_MESSAGE);
        }
        try {
            $created = $repo->enqueue($invoiceId, $correlationId, $allowUnpaid, $origem);
        } finally {
            $lock->release($invoiceId);
        }

        $logRepo = new LogRepository();
        if (!$created) {
            $logRepo->insert(
                null,
                'QUEUE_ENQUEUE_SKIP_ACTIVE',
                json_encode(['invoiceid' => $invoiceId, 'origem' => $tipoLog], JSON_UNESCAPED_UNICODE),
                null,
                $correlationId
            );
            return false;
        }

        $logRepo->insert(null, $tipoLog, json_encode(['invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
        return true;
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

        // Roda sob o lock do cron (CronService e "Processar fila agora"), então nenhum
        // outro lote está em andamento: um RUNNING antigo é de processo que morreu.
        $this->recoverStaleRunningItems();

        $resolvedLegacy = (new QueueRepository())->resolveErrorsForIssuedInvoices(100);
        if ($resolvedLegacy > 0) {
            (new LogRepository())->insert(
                null,
                'QUEUE_RESOLVED',
                json_encode(['mode' => 'backfill', 'resolved_items' => $resolvedLegacy], JSON_UNESCAPED_UNICODE),
                null
            );
        }

        $waitInterval = (int) ($config['queue_wait_status_interval_seconds'] ?? 120);
        if ($waitInterval < 30) {
            $waitInterval = 30;
        }
        if ($waitInterval > 3600) {
            $waitInterval = 3600;
        }

        $this->processStatusBatch($limit, $waitInterval);
        $this->processEmissaoBatch($limit, $waitInterval, $config);
    }

    /**
     * Devolve à fila os itens presos em RUNNING há mais de QueueStatePolicy::RUNNING_STALE_SECONDS.
     * Itens com DPS já enviada vão para WAIT_STATUS (continuam "em voo", preservando a ordem
     * sequencial); os demais voltam para PENDING, e o claim seguinte reavalia a nota.
     */
    public function recoverStaleRunningItems(): int
    {
        $queueRepo = new QueueRepository();
        $now = time();
        $cutoff = date('Y-m-d H:i:s', $now - QueueStatePolicy::RUNNING_STALE_SECONDS);
        $rows = $queueRepo->findStaleRunning($cutoff);
        if (empty($rows)) {
            return 0;
        }

        $notaRepo = new NotaRepository();
        $logRepo = new LogRepository();
        $recovered = 0;

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $since = QueueStatePolicy::runningSince($row);
            if ($id <= 0 || !QueueStatePolicy::isRunningStale($since, $now)) {
                continue;
            }

            $nota = $invoiceId > 0 ? $notaRepo->findByInvoiceId($invoiceId) : null;
            $tentativas = (int) ($row['tentativas'] ?? 0);
            $correlationId = trim((string) ($row['correlation_id'] ?? ''));
            // Contador pelos logs do próprio item (correlation_id indexado), zerado pelo reprocessar manual.
            $previousRecoveries = $logRepo->countByCorrelationSinceLast(
                $correlationId,
                'QUEUE_RUNNING_RECOVERED',
                'QUEUE_MANUAL_RETRY'
            );
            $target = QueueStatePolicy::recoveryTargetStatus($nota, $tentativas, $previousRecoveries);
            $message = sprintf(
                'Item recuperado automaticamente após ficar em RUNNING desde %s (processo interrompido).',
                (string) $since
            );
            if ($target === 'ERROR' && QueueStatePolicy::recoveryTargetStatus($nota, $tentativas) === 'WAIT_STATUS') {
                $message .= sprintf(
                    ' O item já foi recuperado %d vezes sem concluir a consulta: o processo parece cair sempre nesta'
                    . ' fatura (erro fatal, memory_limit ou max_execution_time). Marcado como ERROR para não travar'
                    . ' a fila; verifique os logs e use Consultar Status na fatura ou Reprocessar.',
                    $previousRecoveries
                );
            } elseif ($target === 'ERROR') {
                $message .= ' Tentativas automáticas esgotadas.';
            }

            if (!$queueRepo->recoverStaleRunning($id, $target, $message)) {
                continue;
            }

            $recovered++;
            $logRepo->insert(
                $nota ? (int) ($nota['id'] ?? 0) : null,
                'QUEUE_RUNNING_RECOVERED',
                json_encode([
                    'queue_id' => $id,
                    'invoiceid' => $invoiceId,
                    'running_since' => $since,
                    'tentativas' => $tentativas,
                    'nota_status' => $nota['status'] ?? null,
                    'recuperacoes_anteriores' => $previousRecoveries,
                    'novo_status' => $target,
                ], JSON_UNESCAPED_UNICODE),
                $message,
                $correlationId !== '' ? $correlationId : null
            );
        }

        return $recovered;
    }

    private function processEmissaoBatch(int $limit, int $waitIntervalSeconds, array $config): void
    {
        $queueRepo = new QueueRepository();
        if ($limit <= 0 || $queueRepo->hasInFlightSequentialJob()) {
            return;
        }

        $nfseService = new NfseService();
        $notaRepo = new NotaRepository();
        $logRepo = new LogRepository();
        $invoiceRepo = new WhmcsInvoiceRepository();
        $eligibilityChecker = new EmissionEligibilityService();
        $errorClassifier = new QueueErrorClassifierService();

        for ($processed = 0; $processed < $limit; $processed++) {
            if ($queueRepo->hasInFlightSequentialJob()) {
                break;
            }

            $jobs = $queueRepo->claimNext(1);
            if (empty($jobs)) {
                break;
            }

            $job = $jobs[0];
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
                $mustWaitBeforeNextEmission = false;
                $notaBefore = $notaRepo->findByInvoiceId($invoiceId);
                if ($this->shouldMarkQueueDone($notaBefore)) {
                    $queueRepo->markDone($id);
                    $this->sendAutomaticEmailAfterQueueDone($invoiceId, (int) ($notaBefore['id'] ?? 0), $notaRepo, $logRepo, $config, $correlationId);
                    $logRepo->insert(null, 'QUEUE_SKIP_ALREADY_EMITIDA', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
                    continue;
                }
                if ($this->shouldKeepWaitingForStatus($notaBefore)) {
                    $queueRepo->markWaitStatus($id, $waitIntervalSeconds, $notaBefore ? (string) ($notaBefore['erro_api'] ?? '') : null);
                    $logRepo->insert(null, 'QUEUE_WAIT_STATUS', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
                    break;
                }

                $invoice = $invoiceRepo->getInvoice($invoiceId);
                // Item da geração com a fatura ainda Unpaid: reavalia a política com os dados atuais
                // (gatilho, grupo, custom field e gateway podem ter mudado desde o enfileiramento).
                if (EmissionPolicyService::queueItemNeedsGeradaRecheck($job, $invoice)) {
                    $ctx = (new EmissionPolicyContextLoader())->load($invoiceId, $config);
                    $invoice = $ctx['invoice'];
                    $recheck = (new EmissionPolicyService())->recheckQueuedGerada(
                        $job,
                        $invoice,
                        $ctx['client'],
                        $config,
                        $ctx['gateway'],
                        $notaBefore
                    );
                    if ($recheck !== null && !$recheck->emit) {
                        $queueRepo->markDone($id);
                        $logRepo->insert(
                            null,
                            'QUEUE_SKIP_POLICY',
                            json_encode([
                                'queue_id' => $id,
                                'invoiceid' => $invoiceId,
                                'evento' => EmissionPolicyService::EVENT_GERADA,
                                'origem' => EmissionPolicyService::QUEUE_ORIGEM_GERADA,
                            ] + $recheck->toLogContext(), JSON_UNESCAPED_UNICODE),
                            $recheck->message,
                            $correlationId
                        );
                        continue;
                    }
                }
                // Item da geração: a política autorizou a fatura Unpaid (no enfileiramento e acima).
                $allowUnpaid = EmissionPolicyService::queueAllowsUnpaid($job, $invoice, $config);
                $eligibility = $eligibilityChecker->check($invoice, $allowUnpaid);
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
                        case EmissionEligibilityService::SKIP_ZERO_VALUE:
                            $logRepo->insert(
                                null,
                                'QUEUE_SKIP_ZERO_VALUE',
                                json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId, 'total' => $eligibility['total'] ?? 0.0], JSON_UNESCAPED_UNICODE),
                                null,
                                $correlationId
                            );
                            break;
                    }
                    continue;
                }

                // Agregadora de Mass Pay: recalcula o valor na hora de emitir (uma filha pode ter ganhado
                // nota depois do enfileiramento). Sem valor a declarar, o item vai para DONE.
                $nfseValue = (new NfseValueResolver())->resolve($invoice, $invoiceRepo->getItems($invoiceId));
                if ($nfseValue['excluded_children'] !== [] && $nfseValue['value'] <= 0) {
                    $queueRepo->markDone($id);
                    $logRepo->insert(
                        null,
                        'QUEUE_SKIP_POLICY',
                        json_encode([
                            'queue_id' => $id,
                            'invoiceid' => $invoiceId,
                            'motivo' => EmissionPolicyService::CODE_MASS_PAY_CHILD_ALREADY_ISSUED,
                            'excluded_children' => $nfseValue['excluded_children'],
                        ], JSON_UNESCAPED_UNICODE),
                        EmissionPolicyService::MSG_MASS_PAY_CHILD_ISSUED,
                        $correlationId
                    );
                    continue;
                }

                $nfseService->emitir($invoiceId, $correlationId, ['allow_unpaid' => $allowUnpaid]);
                $nota = $notaRepo->findByInvoiceId($invoiceId);
                if ($this->shouldMarkQueueDone($nota)) {
 $queueRepo->markDone($id);
 $this->sendAutomaticEmailAfterQueueDone($invoiceId, (int) ($nota['id'] ?? 0), $notaRepo, $logRepo, $config, $correlationId);
 $logRepo->insert(null, 'QUEUE_DONE', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
 } elseif ($this->shouldKeepWaitingForStatus($nota)) {
                    $queueRepo->markWaitStatus($id, $waitIntervalSeconds, $nota ? (string) ($nota['erro_api'] ?? '') : null);
                    $logRepo->insert(null, 'QUEUE_WAIT_STATUS', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
                    $mustWaitBeforeNextEmission = true;
                } else {
                    $status = $nota ? (string) ($nota['status'] ?? '') : '';
                    $err = $nota ? (string) ($nota['erro_api'] ?? '') : '';
                    $queueRepo->markError($id, $err !== '' ? $err : ('Status final: ' . $status));
                    $logRepo->insert(null, 'QUEUE_ERROR', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), $err !== '' ? $err : ('Status final: ' . $status), $correlationId);
                }

                if ($mustWaitBeforeNextEmission || $queueRepo->hasInFlightSequentialJob()) {
                    break;
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

                break;
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
                if ($this->shouldMarkQueueDone($nota)) {
 $queueRepo->markDone($id);
 $this->sendAutomaticEmailAfterQueueDone($invoiceId, (int) ($nota['id'] ?? 0), $notaRepo, $logRepo, (new ConfigRepository())->get(), $correlationId);
 $logRepo->insert(null, 'QUEUE_DONE', json_encode(['queue_id' => $id, 'invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
 } elseif ($this->shouldKeepWaitingForStatus($nota)) {
 $queueRepo->touchWaitStatus($id, $nextInterval);
                } else {
                    $status = $nota ? (string) ($nota['status'] ?? '') : '';
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

    /**
     * Envio automático após um DONE fora do lote (ex.: "Consultar agora"). Usa o mesmo caminho do
     * processBatch: respeita auto_send_nfse_email e a proteção contra duplicidade (claimAutomaticEmail).
     * Nunca lança exceção: o item já está DONE e uma falha aqui não pode mudar o status da fila.
     */
    public function sendAutomaticEmailForDoneItem(int $invoiceId, ?string $correlationId = null): void
    {
        try {
            $notaRepo = new NotaRepository();
            $nota = $notaRepo->findByInvoiceId($invoiceId);
            if (!$this->shouldMarkQueueDone($nota)) {
                return;
            }

            $correlationId = trim((string) $correlationId);
            $this->sendAutomaticEmailAfterQueueDone(
                $invoiceId,
                (int) ($nota['id'] ?? 0),
                $notaRepo,
                new LogRepository(),
                (new ConfigRepository())->get(),
                $correlationId !== '' ? $correlationId : CorrelationIdGenerator::generate($invoiceId)
            );
        } catch (\Throwable $e) {
        }
    }

    private function sendAutomaticEmailAfterQueueDone(int $invoiceId, int $notaId, NotaRepository $notaRepo, LogRepository $logRepo, array $config, string $correlationId): void
 {
 if ((string) ($config['auto_send_nfse_email'] ?? '0') !== '1') {
 return;
 }

 try {
 if (!$notaRepo->claimAutomaticEmail($invoiceId)) {
 return;
 }
 (new InvoiceEmailService())->sendToClient($invoiceId);
 $notaRepo->markAutomaticEmailSent($invoiceId);
 $logRepo->insert($notaId, 'EMAIL_NFSE_AUTO_SENT', json_encode(['invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), null, $correlationId);
 } catch (\Throwable $e) {
 $message = $e->getMessage() !== '' ? $e->getMessage() : 'Falha no envio automático da NFS-e.';
 try {
 $notaRepo->markAutomaticEmailFailed($invoiceId);
 } catch (\Throwable $ignored) {
 }
 try {
 $logRepo->insert($notaId, 'EMAIL_NFSE_AUTO_ERROR', json_encode(['invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), $message, $correlationId);
 } catch (\Throwable $ignored) {
 }
 try {
 (new InvoiceHistoryService())->append($invoiceId, 'Falha no envio automático do e-mail da NFS-e. Motivo: ' . $message);
 } catch (\Throwable $ignored) {
 }
 }
 }

 private function shouldMarkQueueDone(?array $nota): bool
 {
        if (!$nota) {
            return false;
        }

        return trim((string) ($nota['status'] ?? '')) === 'EMITIDA'
            && trim((string) ($nota['chave_acesso'] ?? '')) !== '';
    }

    private function shouldKeepWaitingForStatus(?array $nota): bool
    {
        if (!$nota) {
            return false;
        }

        $status = trim((string) ($nota['status'] ?? ''));
        $chave = trim((string) ($nota['chave_acesso'] ?? ''));
        $idDps = trim((string) ($nota['id_dps'] ?? ''));

        if ($status === 'PROCESSANDO') {
            return true;
        }

        if ($chave === '' && $idDps !== '' && !in_array($status, ['CANCELADA', 'REJEITADA', 'ERRO'], true)) {
            return true;
        }

        return $status === 'EMITIDA' && $chave === '';
    }
}
